---
name: write-task
description: Turn identified problems into task files under .tasks/, one file per problem, in the format the existing tasks use. Use when asked to write up findings as tasks, when pointed at a review file (RESULTS.md, docs/*-review.md, a HANDOFF), or when handed a defect in prose to record. Writes a task only where a real fix exists and nothing needs deciding first; anything that needs a decision is asked immediately, before any file is written.
---

# Write task

A task is a **standing brief for whoever implements the fix**, written so it can be
picked up cold: the defect stated as a fact, the evidence that it is real, and a fix
concrete enough to apply without re-deriving it. `.tasks/` is gitignored scratch — the
tasks are how a finding survives from the run that found it to the run that fixes it.

## Input

One of three, in this order of precedence:

1. **A file argument** — `RESULTS.md`, `docs/*-review.md`, a `HANDOFF*.md`. Read the
   whole file. Its findings are candidates, not tasks: a review's own verdict does not
   survive contact with the current tree (see *Gate 1*).
2. **Prose in the argument** — a defect described directly. Locate it in `src/`
   yourself; a task cites lines, so nothing gets written until you have them.
3. **No argument** — the problems already identified in this conversation.

## The two gates

Every candidate passes both, or it is not written. Both are decided **before** any file
is created.

### Gate 1 — there is a fix, and it is still needed

- **The mechanism is traced to a line.** A symptom without a path from input to the
  failing statement is not a task; it is a thing to investigate.
- **The fix is nameable in code.** You can write the replacement lines. "This should be
  more robust" is not a fix.
- **The fix belongs in this tree.** Not in WooCommerce, WordPress, WCS or the Scanpay
  API. A workaround for someone else's bug is a task; their bug is not.
- **It is not already fixed.** Read the current file, never the review's account of it.
  `RESULTS.md` finding 3 carries *"FIXED after this review"* in its own body — a finding
  can be stale before you reach it, and the tree is the authority.
- **It is not in `AGENTS.md` § Settled.** Ping keepalive, lock-free charge concurrency,
  the unscoped admin-AJAX secret, the write-once API key, version-stamped-last
  migrations. Re-proposing one of these is the failure that section exists to prevent.
- **It is a defect, not a preference.** § *Code style: procedural and modular* is the
  house style, not a backlog: no task turns a function into a class, adds an
  autoloader, a dependency, an interface or a DTO. A style change earns a task only when
  the current form causes a failure you have traced.

### Gate 2 — nothing is left to decide

A task states what to do. If any of these is open, **ask, before writing anything**:

- **The fix changes what the code promises**, not how it keeps the promise — a new
  status transition, a different order note, an endpoint that starts refusing something
  it accepted.
- **Two or more defensible fixes** with different costs, and neither `AGENTS.md` nor the
  surrounding code settles the choice.
- **The fix touches a boundary the guide fences off** — a version floor
  (`docs/requirements.md`), the shipped dependency count, anything under § Settled.
- **Scope is ambiguous** — the same defect exists at four sites and it is not obvious
  whether the task covers one or all four.

How to ask:

- **Investigate every candidate first**, so you know every open question, then ask them
  in **one** `AskUserQuestion` call. Do not interleave asking and writing.
- Ask about the **decision**, not the defect: give the options you actually found, with
  the cost of each. Recommend one.
- The answer is then *in* the task as settled fact. A task never contains "it should be
  clarified whether…" — an open question in a written task means Gate 2 was skipped.
- An answer may retire the candidate entirely. That is a correct outcome; report it.

**A separable design decision is not a Gate 2 blocker.** Where the defect has one fix
and something larger is *also* worth doing, write the task for the fix and record the
larger thing under a `### Worth considering alongside it (a design decision, not part of
the defect)` heading — as `task-4.md` does with progress-recording. Ask only when the
fix itself cannot be written without the answer.

## Numbering

`.tasks/task-<N>.md`, `<N>` continuing from the highest that exists — numbers are never
reused and never renumbered, so a reference to "task 3" in a commit message or a review
keeps pointing at the same thing. The `N` in the `# Task N` heading matches the
filename. Never overwrite or delete an existing task.

## The file format

````markdown
# Task N — <the defect as a statement of fact>

**File:** `src/path/to/file.php` — `function_name()`

## The problem

<The quoted code, then the mechanism.>

## Proposed fix

<The replacement code, then why it is the right one.>
````

**The heading is a claim, not an instruction.** *"The bulk 'Capture and complete' loop
has no execution-time budget"*, not *"Add set_time_limit() to the bulk loop"*. It names
what is wrong; the fix section says what to do about it. Em dash after `Task N`.

**The file line** is `**File:**` for one, `**Files:**` for several, immediately under the
heading. Paths are repo-relative from `src/`. Add `:line`, `:line-line` or a function
name when it narrows things usefully — and prefer the function name where lines will
drift.

**`## The problem`** opens with the offending code, quoted verbatim from the current
tree, elided with `…` where the middle does not matter. Then the mechanism: what
happens, in what order, and what it costs. Trace the escape to its callers by name and
line. Quote the surrounding comment or docblock when the code already documents the rule
it breaks — `task-5.md` quoting `report_incomplete()`'s own docblock is the model, and it
is the strongest evidence a task can carry.

**`## Proposed fix`** gives the code to write, including its comments — the comments are
part of the deliverable, and § *Comments* governs them. Where a fix has parts, number
them and say which is load-bearing and why: both `task-3.md` and `task-4.md` spend a
paragraph on why the up-front `set_time_limit()` cannot be dropped as redundant with the
renewal, because that is the half a reader would otherwise remove.

**`###` subheadings** carry anything that would otherwise bloat the two sections. Use
them freely; the existing tasks show the recurring ones:

| Heading | For |
| --- | --- |
| `### What this is and is not` | bounding a defect that looks larger than it is |
| `### What a kill mid-loop costs` | the concrete damage, per interruption point |
| `### Reachability, and why it should be closed anyway` | narrow triggers, argued honestly |
| `### After the change` | what to run, regenerate or commit alongside |
| `### What is deliberately left unguarded` | the sites you decided not to touch, so a later reader does not re-find them |
| `### The alternative, stated so the choice is explicit` | the do-nothing case, given its due |
| `### Worth considering alongside it (a design decision, not part of the defect)` | the separable improvement |

## Writing it

The register is `AGENTS.md` § *Comments* applied at length: **the constraint, the
mechanism, the reason — never a restatement of the code above it.** Prose, English, full
sentences, no bullet-point shorthand where a sentence carries more.

- **Evidence is cited, not asserted.** Vendor and stub sources by path and line
  (`wp-admin/includes/plugin.php:1317-1327`), measured results with the tool and version
  that produced them (`msgfmt --check`, gettext 0.23.2, exit code), WC versions with the
  file they came from. Anything you did not verify is marked as unverified.
- **Argue against yourself.** Where an objection would retire the defect —
  *"the web server kills it first anyway"* — answer it in the task. Where the fix has a
  tempting wrong form — gettext's `nplurals=INTEGER` placeholder — say what it costs and
  why the concrete value wins.
- **Say what the fix does not cover.** Scope stated explicitly beats scope inferred.
- **Check against the guide and name what you checked.** § Settled, `docs/requirements.md`
  for floors, `docs/*-review.md` for ground already covered.
- **The linters are the validation** (§ *Build and validation*). Where a fix needs
  `./build.sh` (anything touching `src/languages/`), `pnpm phpcs`, `pnpm lint:js`,
  `pnpm lint:style` or `pnpm exec tsc`, say so under `### After the change`. Where
  correctness needs a running shop, say that it stays unverified rather than implying
  otherwise.
- **Never write to `src/`.** This skill produces `.tasks/` files and nothing else, even
  when the fix is a one-liner you could apply in less time than the task takes to write.

## Report

Afterwards, in the chat only — nothing about skipped candidates goes to disk:

- the tasks written, as number and heading;
- every candidate that did not become one, with which gate stopped it and why —
  already fixed, upstream, § Settled, retired by your answer, or still needing a
  decision you could not get.
