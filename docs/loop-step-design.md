# Loop-over-array step execution — design plan

## Problem

The data filter (input mappings) handles **shape transformation** — it maps output properties to input fields, 1:1. The wildcard (`*`) mapping collects a subproperty from every array element into a single array value, but the step still executes **once** with that array as input.

The user needs a fundamentally different concept: **execution cardinality** — Step 1 returns `order_ids: [101, 202, 303]`, Step 2 accepts a single `order_id`, and the workflow should execute Step 2 **three times**, once per element. This is a loop, not a filter.

## Design principle

> Data filters change the *shape* of data between steps.
> Loops change the *number of times* a step runs.

These are orthogonal concerns. The data filter is responsible for getting data into the step's input fields (including producing arrays via wildcard mapping). The loop simply says "one of these input fields is an array — run the step once per element of that array." The loop does not have its own source, path, or mapping. It just names the input field to iterate over.

---

## 1. Workflow definition schema changes

Add an optional `loop` object to each step:

```json
{
  "ability": "plugin/process-order",
  "input": {},
  "loop": {
    "field": "order_ids"
  },
  "input_mappings": [
    { "source": "previous", "path": "orders.*.id", "target": "order_ids" }
  ]
}
```

| Field | Type | Description |
|-------|------|-------------|
| `loop.field` | string | The name of the input field that contains the array to iterate over. The data filter populates this field (via mappings or static input); the loop just decides to iterate over it. |

**What stays the same:** `input`, `use_previous_output`, `input_mappings` all work exactly as before. No new mapping source is introduced. The data filter produces the array value for the named field, and the loop iterates over it.

**What the loop does at runtime:**

1. Data filter resolves all mappings → produces the step's full input (e.g., `{ order_ids: [101, 202, 303], action: "process" }`).
2. Loop extracts the array from `input["order_ids"]`.
3. For each element, creates a new input copy with `order_ids` replaced by the single element (`101`, then `202`, then `303`). Other fields remain constant.
4. Executes the ability once per iteration.
5. Collects outputs into an array.

**Output of a looped step:** The step's `output` becomes an **array** of per-iteration outputs. The next step sees this array as its `previous` data and can use wildcard mappings (`*.property`) to pluck across all iterations.

---

## 2. Runner changes (`class-workflow-runner.php`)

The current step loop in `run()`:

```
for each step:
    resolve input from static + mappings
    execute ability once
    store output as previous_output
```

New logic:

```
for each step:
    resolve input from static + mappings (existing behavior, no changes)
    if step has loop config and loop.field is set:
        extract array from input[loop.field]
        if not array → warning, execute once (fallback)
        if empty array → skip step, set previous_output = []
        for each element in array:
            copy input, replace loop.field with single element
            execute ability
            collect output
        store outputs[] as previous_output
    else:
        execute ability once (existing behavior)
        store output as previous_output
```

**Key changes in `Baton_Workflow_Runner::run()`:**

- After resolving the step's input (via existing mapping logic — no changes there), check for a `loop` config.
- If present, extract the array from `input[loop.field]` using `Baton_Input_Mapper::get_value_at_path()` (supports dot paths for nested fields).
- Wrap the single-step execution in a `foreach` over the loop array.
- For each iteration, create a copy of the input with the loop field replaced by the single element.
- After the loop, set `$previous_output` to the array of collected outputs (or the single output if no loop).
- Fire `baton_before_step` / `baton_after_step` hooks once per iteration (with an iteration index in the context).

**No changes to `Baton_Input_Mapper::apply_mappings()`** — the mapper doesn't need to know about loops. It resolves mappings as usual, producing the full input object. The runner then splits the array field into iterations.

**Edge cases:**

| Case | Behavior |
|------|----------|
| Loop field is empty array | Step is skipped, `previous_output = array()`, warning emitted |
| Loop field is not an array | Fall back to single execution, warning emitted |
| Loop field doesn't exist in input | Same as empty array — skip, warning |
| Step has loop config but the field was populated statically | Works fine — if `input.action = ["a", "b"]` and `loop.field = "action"`, iterates twice |
| Nested workflow inside a loop | Works naturally — each iteration invokes the sub-workflow independently. Cycle detection via `workflow_stack` is unaffected. |

---

## 3. Sanitization changes (`class-workflow-cpt.php`)

`sanitize_definition()` needs to recognize and sanitize the `loop` key on each step:

```php
if ( isset( $step['loop'] ) && is_array( $step['loop'] ) ) {
    $loop_field = isset( $step['loop']['field'] )
        ? sanitize_text_field( (string) $step['loop']['field'] )
        : '';

    if ( '' !== $loop_field ) {
        $definition['steps'][ $i ]['loop'] = array(
            'field' => $loop_field,
        );
    }
}
```

If `loop.field` is empty or missing, the loop config is silently dropped — the step executes once as before.

---

## 4. Schema path awareness for loop field selection

The loop panel needs to show the user which input fields are array-typed, so they can pick the right field to iterate over. This comes from the **current step's input schema** (not the previous step's output schema).

`Baton_Schema_Paths::get_paths()` already returns a `value` and `label` for each path. To filter to array-typed fields only, we should:

- **Add a `type` field to each path entry** (currently only `value` and `label`), and let JS filter on `type === 'array'`.

This is a small change to `collect_paths()` and the type information is useful for the UI in other contexts too (e.g., showing an array icon next to array-typed paths in the data filter target dropdown).

The JS then filters the step's `input_targets` (which come from the step's own input schema) to only show array-typed fields in the loop field dropdown.

---

## 5. UI changes — secondary panel on the step card

The loop config is **not** a data filter and should **not** live in the connector/filter slot between steps. Instead, it's a step-level property shown as a collapsible panel **within the step card itself**.

### Layout

```
┌─────────────────────────────────────────┐
│  Step 2: Process Order          [IO chips]│
│  ┌─────────────────────────────────────┐ │
│  │ Ability: [plugin/process-order    ▼] │ │
│  └─────────────────────────────────────┘ │
│  ┌─────────────────────────────────────┐ │
│  │ ↻ Loop                          [✓]  │ │  ← checkbox toggle
│  │   Iterate over: [order_ids     ▼]    │ │  ← only array-typed input fields
│  │   "Runs this step once per element   │ │
│  │    in the selected input field"       │ │
│  └─────────────────────────────────────┘ │
│  [Static input textarea]                  │
└─────────────────────────────────────────┘
       │ data filter (input_mappings) │
       ▼                               ▼
┌─────────────────────────────────────────┐
│  Step 3: ...                             │
└─────────────────────────────────────────┘
```

### Interaction

1. **Loop toggle** — A checkbox "Loop" on the step card. When unchecked, the field dropdown is hidden and the step behaves normally.
2. **Iterate over dropdown** — Populated from the **current step's input schema paths filtered to array type only**. Uses the same `indentNestedLabel()` formatting. Empty/disabled if the step's input schema has no array-typed fields.
3. **Data filter is unchanged** — The user still uses the data filter between steps to map the previous step's output into this step's input fields (including using wildcard `*` mapping to produce an array for the loop field). The loop just determines that one of those fields should be iterated over.

### Example workflow

1. Step 1 (`orders-producer`) returns `{ orders: [{id: 101, ...}, {id: 202, ...}], total: 3 }`
2. Data filter between Step 1 and Step 2: map `orders.*.id` → `order_ids` (produces `[101, 202]`)
3. Step 2 (`process-order`) has loop enabled with field `order_ids`
4. Runner executes Step 2 twice:
   - Iteration 1: input `{ order_ids: 101 }` → output `{ processed_id: 101, status: "done" }`
   - Iteration 2: input `{ order_ids: 202 }` → output `{ processed_id: 202, status: "done" }`
5. Step 2's aggregated output: `[{ processed_id: 101, ... }, { processed_id: 202, ... }]`
6. Step 3 can use wildcard mapping `*.processed_id` to get `[101, 202]` if needed

### State in `App.js`

Add `loop` to the step state object:

```js
{
  ability: 'plugin/process-order',
  input: {},
  input_mappings: [...],
  loop: null  // or { field: 'order_ids' }
}
```

The `defaultStep()` helper in `utils.js` gets `loop: null`. The `definitionFromSteps()` builder passes `loop` through if non-null.

### CSS

A new `.baton-loop-panel` class (styled similarly to `.baton-filter-node` but with a distinct accent color — purple/indigo to visually separate it from the blue data filter slots).

---

## 6. Admin run report changes (`assets/admin-run.js`)

The AJAX run report currently shows one row per step. For looped steps, it should show:
- The step header with an iteration count badge (e.g., "Step 2 — 3 iterations")
- A collapsible sub-list showing each iteration's output (collapsed by default if > 1 iteration)
- The aggregated output array

This is a progressive enhancement — the existing report structure works, just needs sub-row rendering for looped steps.

---

## 7. Test plan

### PHP tests

| File | New tests |
|------|-----------|
| `Test_Workflow_Cpt.php` | `sanitize_definition` preserves `loop` config; strips invalid/empty loop configs |
| `Test_Workflow_Runner.php` | E2E: loop over array of IDs, each iteration gets correct single-element input, outputs collected as array; empty array → skip + warning; non-array field → fallback to single execution; loop field with nested dot path |

### Test fixtures needed

- `baton-test/process-order` — accepts a single `order_id` (integer), returns `{ processed_id: <id>, status: "done" }`
- Reuse existing `baton-test/orders-producer` (returns `{ orders: [...], total: N }`)

### JS

No automated JS tests currently exist. Manual smoke test:
1. Create a 2-step workflow: orders-producer → process-order
2. Data filter: map `orders.*.id` → `order_id` (produces `[101, 202, 303]`)
3. Enable loop on Step 2, select field `order_id` (actually `order_ids` — depends on the consumer ability's input schema)
4. Save, run, verify 3 iterations each processing one ID

---

## 8. Migration / backward compatibility

- Steps without `loop` (or `loop: null`) execute exactly as before — zero behavior change.
- `input_mappings` are completely unaffected — no new source type, no changes to mapper logic.
- `sanitize_definition()` silently drops `loop` configs with empty/missing fields, so malformed data from older editors doesn't break.
- The `build/index.js` recompile picks up the JS changes; no database migration needed since the definition is stored in post meta as JSON.

---

## 9. Implementation order

| Phase | Scope | Files |
|-------|-------|-------|
| **1** | Runner loop logic + sanitization (backend) | `class-workflow-runner.php`, `class-workflow-cpt.php` |
| **2** | Schema path type annotations | `class-schema-paths.php` |
| **3** | Test fixtures + PHP tests | `test-abilities.php`, `Test_*.php` |
| **4** | Editor UI (loop panel on step card) | `src/editor/App.js`, `src/editor/utils.js`, `assets/baton-editor.css` |
| **5** | Rebuild + admin run report | `build/`, `assets/admin-run.js` |
| **6** | Static checks + full test run | `npm run check`, `npm run test:php` |

Each phase is independently testable — phases 1–3 can be validated with PHP tests before any UI work begins. Notably, **no changes to `class-input-mapper.php` are needed** since the mapper is not involved in loop logic.

---

## Decisions

1. **Output aggregation shape** — Flat array `[{...}, {...}, {...}]`. Simpler, and works naturally with the existing wildcard `*` mapping for downstream steps.

2. **Loop field with dot paths** — Yes, support dot paths from day one (e.g., `"order.items"` to iterate over a nested array). The runtime infrastructure (`get_value_at_path()`) already handles this; the UI dropdown just needs to show nested array fields from the input schema.

3. **Loop scope** — Single step only for now. The door is open for multi-step loop ranges in the future (the runner would need to buffer and replay a sub-sequence), but that's deferred.