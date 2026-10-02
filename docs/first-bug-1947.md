# The First Computer Bug — 9 September 1947 🦋

**Refs #1288** (`task`: *Document in TestLink upgraded in documentation about first bug ever
found on Sept 9, 1947*)

Mirror of the wiki page **[Prima Bug din Istorie](https://github.com/sebiboga/testlink-upgraded/wiki/Prima-Bug-Istorica)**
(Romanian, the wiki language). This file is the English `docs/` twin — no image lines, per the
project documentation convention.

On **9 September 1947**, while **Grace Hopper**'s team worked on the **Harvard Mark II**
relay computer in Cambridge, Massachusetts, an operator found a **moth trapped in a relay** —
they removed it with tweezers, **taped it into the machine's log book** with scotch tape and
wrote the sentence that entered computing history:

> *"Found actual case of first bug being made."*

This is the **first documented computer bug**, and the origin of the story that turned the word
*bug* — a term centuries old for a mechanical defect — into the international symbol of **bug
tracking**. TestLink lives on that story: defect management, execution results and the event
audit trail (Event Viewer) are its direct descendants.

---

## 1. Timeline of 9 September 1947

| Moment | Event |
|--------|-------|
| **9 Sep 1947** | A **moth** is found jammed in a relay of the **Harvard Mark II** (relay computer built by Howard Aiken at the *Harvard School of Applied Research and Computer Science*, running 1945–1947). |
| **Immediately after** | The moth is removed with tweezers and **taped with adhesive tape into the machine's log page**, next to the note about the defect. |
| **Next days** | Grace Hopper's team does not stop at removing the insect: it **reaches its own diagnosis** — isolates the relay, checks it, finds a real mechanical cause and repairs it. |
| **~69 hours later** | The machine is fully operational again (duration quoted in the team's later accounts of the incident). |

The essential point: **the moth was the symptom, the cause was something else.** The moth was
wedged into a contact of a relay that was already loose — the real defect was a **mechanical
tightening** problem, not an "animal in the computer". The story only becomes relevant to QA at
the step where the team did not settle for "we removed the insect and we're done".

> **Accuracy note:** the "first computer bug" label is conventionally attached to this incident,
> but no earlier record of a *software* defect catalogued as such exists. Defects were documented
> before 1947 (see §5) — hence the careful wording: **first documented computer bug**.

---

## 2. The original log

Harvard Mark II had no display: it lived on **punched cards**, and operations were written by
hand in the **machine's operating log**, with observations and corrections recorded directly by
the operators. The 9 September 1947 page contains:

- the note in **plain English** with the incriminating text,
- the **schematic drawing** of the relay circuit involved, with an "X" at the faulty position,
- the **moth itself**, dried and taped to the edge of the paper.

The triple **moth + note + circuit** is the oldest modern record of a *debugging* process:
**observe → document → diagnose → repair → put back in service**. The moth and the log page are
now held by the **Smithsonian — National Museum of American History** (Washington, D.C.),
exhibited as "the first bug in computing history".

---

## 3. Not just the moth: the real diagnosis

The popular story stops at "they found a moth". What follows is the part that matters for
testing:

1. **Symptom** — the machine failed intermittently, with inexplicable errors.
2. **Observation** — an operator notices a foreign body in a relay.
3. **Isolation** — the fault is tied to **one specific relay**, not to the whole machine.
4. **Verification** — **every screw** on the relay/contact panels is checked; a **screw not
   tightened to ground** is found on that relay.
5. **Repair** — the screw is tightened and operation resumes; about **69 hours** until full
   return to service.
6. **Lesson** — *"hardware has bugs too"* becomes a verifiable fact and starts being handled as
   such.

> Point by point this is a **test plan**: symptom → reproducible step → cause → fix → retest. The
> only difference from TestLink is that in 1947 the "test plan" was a sheet of paper.

---

## 4. Why it matters for testing and QA

- **Bug tracking has a documented origin.** It is not a marketing convention but a workshop
  practice: *discover → note → isolate → repair → verify*.
- **Hardware and software defects are handled by the same method**, only the location differs.
  A relay bug has an analogue for a "stack trace": the circuit drawn in the log.
- **Auditability matters.** The Mark II log book was an **incident log**; its modern equivalent is
  the `events` table behind the **Event Viewer** screen, where every action (who, when, what) is
  kept and can be filtered, exported and audited — see `gui/templates/eventviewer/eventviewer.html`.
- **The lesson stands:** *"symptom removed"* ≠ *"cause repaired"*. In TestLink this translates into
  **verifying the actual effect of a fix** (retest), not merely marking the defect *Resolved*.

---

## 5. The word "bug" is older than the moth

The word **bug** in the sense of *defect* is much older than 1947:

- **Edison** already used the term for a defect in his phonograph, decades earlier.
- *Buggy* meant a machine with exposed moving parts, exposed to dust and insects — i.e. "it has
  bugs" in the mechanical sense.
- **Ada Lovelace** and other 19th-century authors described programming defects, but with
  period terms (*error*, *glitch* — the word *glitch* is still in use).

What makes **9 September 1947** special is that it is the first case in which a mechanical
defect in an electronically programmable machine was **catalogued, drawn, physically preserved
and tied to a diagnosis** — hence "debugging", a word that survives in every programming language.

---

## 6. From Mark II to TestLink 2.0.1

| 1947 — Harvard Mark II | 2.0.1 — TestLink Upgraded |
|-----------------------|--------------------------|
| Punched paper cards | **BFF REST API** in PHP (`api/**`) |
| Operating log on paper | **Event Viewer** — `events` table, filters, charts, export |
| Defect catalogued with note + circuit | **Execution status**: Passed / Failed / Blocked + bug link |
| Hardware "bug" | **Custom fields** on the test case: priority, severity, execution type |
| Verifying a repair | **Retest** in the same test plan, with the full history kept |
| Team: operator + engineer | Team: Tester / Manager / Admin (see *Users & Roles*) |

The core idea has not changed in 79 years: **an untested defect is an unknown defect**.

---

## 7. Timeline: from the first bug to TestLink

| Year | Event |
|------|-------|
| **1878** | Edison uses the term *bug* for a mechanical defect. |
| **1945–1947** | The Harvard Mark II (relay computer, programmed with punched cards) is in service. |
| **9 Sep 1947** | **First documented computer bug**: the moth in a relay, noted and preserved in the log. |
| **1949** | First vacuum-tube computers (no mechanical relays) — defects become purely electronic. |
| **1952** | Grace Hopper demonstrates the first "compiler" (A-0) — programs become portable and bugs become **logic** defects. |
| **1957** | **FORTRAN** is released — source code becomes a maintained, versioned artefact. |
| **1971** | First microprocessor (Intel 4004) — software testing becomes dominant. |
| **1981** | IBM PC — bugs reach millions of users; traceability becomes a need. |
| **1999** | First "millionth" defect and the rise of open-source bug tracking. |
| **2004** | **TestLink** is released on SourceForge — test plans, results, defects. |
| **1.9.20** | Last legacy version (Smarty). |
| **2.0.1** | **TestLink Upgraded**: Dashio UI, modern HTML screens, PHP REST BFF. |

---

## 8. Logging a real bug in TestLink

The practical connection with the product, in 5 steps:

1. **Test Execution** — run the test and pick the result `Passed` / `Failed` / `Blocked`.
   For a defect: `Failed`.
2. **Link to the defect** — from the execution result you can create or attach an entry in
   **Bug Management**.
3. **Severity and priority** — set **Importance × Urgency**, which drives priority.
4. **Team transparency** — every action is recorded in the **Event Viewer** (who changed the
   result, and when).
5. **Retest after the fix** — after the repair, re-executions are kept as history, so it stays
   visible whether the fix really removed the cause (the Mark II lesson).

---

## 9. Sources and further reading

- **Wikipedia** — [Grace Hopper](https://en.wikipedia.org/wiki/Grace_Hopper),
  [Harvard Mark II](https://en.wikipedia.org/wiki/Harvard_Mark_II),
  [Software bug](https://en.wikipedia.org/wiki/Software_bug)
- **Smithsonian, National Museum of American History** — the "first computer bug" exhibit
  (the moth and the log page)
- **Computer History Museum** — material on the Harvard Mark II and relay programming
- In this project's archive: the **CHANGELOG 2.0.1** and the test suites in
  `tmp/TLU_Test_Cases.md`, which follow the same discipline:
  **observation → documentation → verification**

---

*Documented under issue **#1288**. Project **TestLink Upgraded 2.0.1** — see the
[GitHub Wiki](https://github.com/sebiboga/testlink-upgraded/wiki/Home).*
