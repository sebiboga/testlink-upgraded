# LESSONS-LEARNED.md — Ce am învățat din issue-urile #728 și #722

## Ordinea comentariilor este CRITICĂ

### Greșeala făcută

Pe issue-ul #722 am postat comentariile în ordine greșită:
1. ❌ `## Verified & Fixed` (primul — greșit)
2. ❌ `## ROOT CAUSE` (fără INVESTIGATION înainte)
3. ❌ `## INVESTIGATION` (după ROOT CAUSE — invers)

Pe issue-ul #728 am postat:
1. ❌ `## Fixes applied` (primul — greșit)
2. ❌ `## Verified & Fixed` (al doilea — greșit)
3. ❌ `## Investigation` (al treilea — trebuia primul)
4. ❌ `## ROOT CAUSE` (al patrulea — trebuia al doilea)

### Ordinea CORECTĂ (obligatorie)

```
COMMENT 1: ## INVESTIGATION    → ÎNAINTE de a scrie cod
COMMENT 2: ## ROOT CAUSE       → ÎNAINTE de a implementa fix-ul
COMMENT 3: ## FIX checkpoint   → DUPĂ FIECARE pas implementat
COMMENT 4: ## VERIFICATION     → ÎNAINTE de a închide issue-ul
```

### De ce contează ordinea

1. **Cine citeste issue-ul** trebuie să vadă întâi INVESTIGATION (ce am găsit),
   apoi ROOT CAUSE (de ce se întâmplă), apoi FIX (cum am reparat), apoi
   VERIFICATION (că funcționează).

2. **Orice altă ordine** confuzează cititorul — nu știe ce context are
   înainte de a vedea soluția.

3. **Comentariile GitHub** sunt permanente — odată postate, nu pot fi
   șterse sau reordonate. Singura opțiune este să le postăm corect
   prima dată.

### Reguli de aur

1. **INVESTIGATION întotdeauna primul** — ÎNAINTE de orice cod
2. **ROOT CAUSE după INVESTIGATION** — ÎNAINTE de implementare
3. **FIX checkpoint după fiecare pas** — Nu la sfârșit
4. **VERIFICATION ultimul** — Cu dovezi de commit, test matrix, Event Viewer

### Cum să evităm greșeala

1. **YML-ul impune ordinea** — prompt-ul menționează explicit COMMENT 1, 2, 3, 4
2. **INVESTIGATE-FIX.md are tabel** — cu ordinea obligatorie
3. **Verifică înainte de a posta** — citește comment-urile existente
4. **Nu posta VERIFICATION înainte de FIX** — e ca și cum ai închide
   un lucru pe care nu l-ai făcut

### Verificare rapidă

Înainte de a închide orice issue, verifică:

```bash
gh issue view <n> --json comments --jq '.comments[] | .body | split("\n")[0]'
```

Ordinea trebuie să fie:
```
## INVESTIGATION
## ROOT CAUSE
## FIX checkpoint 1/N — ...
## FIX checkpoint 2/N — ...
## VERIFICATION
```

### Exemplu de ordine corectă (Issue #706)

```
## INVESTIGATION
## ROOT CAUSE
## FIX checkpoint 1/3 — logout.php
## FIX checkpoint 2/3 — lnl.php
## FIX checkpoint 3/3 — index.php
## VERIFICATION
```

### Exemplu de ordine GREȘITĂ (Issue #722 — cum NU trebuie)

```
## Verified & Fixed        ← GREȘIT: nu poți verifica înainte de a investiga
## ROOT CAUSE              ← GREȘIT: nu poți afla cauza înainte de a investiga
## INVESTIGATION           ← GREȘIT: trebuia să fie primul
```

---

## Alte lecții învățate

### 1. Nu folosi 'evil.com' în exemple

Folosește placeholder-uri neutre: `testlink.example.com`, `attacker.example.com`.

### 2. Documentarea e obligatorie, nu opțională

Un fix fără documentare = NOT DONE. Cele 4 comentarii + fișierul docs/
sunt la fel de importante ca și codul în sine.

### 3. Comentariile trebuie să aibă dovezi

Nu scrie "funcționează" — scrie ieșirea curl, output-ul `php -l`,
numărul de warning-uri din Event Viewer.

### 4. Nu închide issue-ul fără toate cele 4 comentarii

Dacă lipsește oricare comentariu (INVESTIGATION, ROOT CAUSE, FIX,
VERIFICATION), issue-ul NU este complet documentat.
