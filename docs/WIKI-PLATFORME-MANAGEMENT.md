# Gestionarea Platformelor 🖥️

## Ce este o Platformă?

O **Platformă** este un mediu specific pe care testezi aplicația:

```
Platforme Tipice:

Hardware:
├─ Windows 10
├─ Windows 11
├─ macOS 12
├─ macOS 13
├─ Ubuntu Linux
└─ CentOS Linux

Browsers:
├─ Chrome 120+
├─ Firefox 121+
├─ Safari 17+
└─ Edge 120+

Mobile:
├─ iOS 15
├─ iOS 16
├─ iOS 17
├─ Android 11
├─ Android 12
└─ Android 13

Combinații:
├─ Windows 11 + Chrome 120
├─ macOS 13 + Safari 17
├─ iOS 17 + Safari
├─ Android 13 + Chrome
└─ etc
```

## Creare Platformă

**Cine poate:** Administrator, Project Manager

### Pași:

1. Mergi la **Proiect → Setări → Platforme**
2. Apasă **Adaugă Platformă Nouă**
3. Completează:

| Camp | Descriere | Exemplu |
|------|-----------|---------|
| **Nume** | Identificator clar | Windows 11 + Chrome 120 |
| **Descriere** | Detalii | "Enterprise OS, latest Chrome" |

4. Apasă **Salvează**

### Exemplu:

```
Nume: iOS 17 + Safari
Descriere: 
Latest iOS version with native Safari browser.
For iPad and iPhone testing.
Supports latest web standards.
```

## Platforme în Test Plan

Când creezi **Test Plan**, selectezi platformele:

```
Plan: Banking v1.5 Release

Platforme Selectate:
├─ Windows 11 + Chrome 120
├─ macOS 13 + Safari 17
├─ iOS 17 (iPhone 15)
└─ Android 13 (Samsung Galaxy)

Fiecare Test Case se execută pe FIECARE platformă!

Exemplu:
TC-001 (Login) va rula pe:
├─ Windows → PASS
├─ macOS → PASS
├─ iOS → FAIL (bug: autofill)
└─ Android → PASS
```

## Matricea Platform-Test

Fiecare combinație (test case + platform) are propriul rezultat:

```
Test Case | Windows | macOS | iOS | Android
-----------|---------|-------|-----|--------
TC-001    | PASS    | PASS  | FAIL| PASS
TC-002    | PASS    | PASS  | PASS| PASS
TC-050    | FAIL    | PASS  | PASS| FAIL
TC-100    | PASS    | FAIL  | N/R | PASS
```

## Platform Coverage Report

Raport: Pe care platforme suntem bine / rău?

```
Platform Analysis:

Windows 11 + Chrome 120:
├─ Tests Pass: 95%
├─ Tests Fail: 3%
├─ Tests N/R: 2%
└─ Status: ✅ GOOD

macOS 13 + Safari 17:
├─ Tests Pass: 92%
├─ Tests Fail: 5%
├─ Tests N/R: 3%
└─ Status: 🟡 MEDIUM

iOS 17 + Safari:
├─ Tests Pass: 85%
├─ Tests Fail: 12%
├─ Tests N/R: 3%
└─ Status: 🔴 NEEDS WORK

Android 13 + Chrome:
├─ Tests Pass: 88%
├─ Tests Fail: 8%
├─ Tests N/R: 4%
└─ Status: 🟡 MEDIUM
```

## Platform-Specific Bugs

Unele bug-uri apar DOAR pe anumite platforme:

```
BUG-2024-0045: Autofill not working
├─ Platform: iOS 17 + Safari
├─ Other platforms: N/A (works fine)
├─ Cause: Mobile Safari specific
├─ Fix: Safari custom handling
└─ Status: In Development

BUG-2024-0046: Font rendering issue
├─ Platform: Windows 10 + Chrome
├─ Other platforms: N/A (renders fine)
├─ Cause: Font cache issue
├─ Fix: Clear cache on load
└─ Status: Resolved
```

## Edita Platformă

1. **Proiect → Platforme → [Selectează]**
2. Apasă **Editează**
3. Schimbă:
   - Nume
   - Descriere
   - Status (Active/Inactive)
4. Apasă **Salvează**

## Șterge Platformă

⚠️ **Atenție:** Dacă ștergi platform, se pierd rezultatele testelor pe acea platformă!

### Alternativă: Dezactivare

1. **Editează Platform**
2. **Status:** Inactive
3. Rămâne în sistem, dar nu apare în teste noi

## Platform Grupos

Poți grupa platforme similar:

```
Desktop:
├─ Windows 11 + Chrome
├─ macOS 13 + Safari
└─ Linux + Firefox

Mobile:
├─ iOS 17
├─ Android 13
└─ Android 12

Legacy (Deprecated):
├─ Windows 7 (no longer test)
├─ IE 11 (no longer test)
└─ (archived for reference)
```

## Best Practices

✅ **DO:**
- **Defini clar:** Ce versiune exactă?
- **Documenare:** Unde testezi?
- **Coverage:** Minimum 3 platforme
- **Track bugs:** Platform-specific
- **Update:** Adaugă noi versiuni

❌ **DON'T:**
- Nu amesteca versiuni (Chrome 115 + Chrome 120)
- Nu lansa fără testat pe principal platforme
- Nu ștergi platforme cu rezultate
- Nu ignora mobile testing

---

**Platforme: Critical pentru release! Verifica totul pe fiecare! 🎯**

---

## Fixed: ArgumentCountError 500 în platforms BFF export la `testproject::getName()` (Issue #534)

**Simptom.** `GET /api/platforms/index.php/export?tproject_id=<id real>` **fără**
parametrul `filename` întorcea HTTP 500 (`ArgumentCountError: Too few arguments
to function testproject::getName()`). Ramura de nume de fișier implicit din
exportul BFF de platforme era complet nefuncțională pentru orice consumator
direct al API-ului. Ecranul **Platform Management** nu era afectat vizibil,
pentru că modalul de export trimite întotdeauna un `filename`.

**Cauza root.** `api/platforms/index.php:369` apela
```php
$tname = str_ireplace(" ", "", $tproject_mgr->getName($tproject_id));
```
— apel de instanță — dar în acest codebase `testproject::getName(&$dbh, $id)`
(librărie: `lib/functions/testproject.class.php:4282`) este **static** și
primește mai întâi handler-ul de bază de date; apelul de instanță transmite un
singur argument, deci PHP 8 aruncă `ArgumentCountError`. Exact același tipar ca
bug-ul din keywords BFF reparat în issue #531.

**Abordarea fix-ului.** Apelul a fost schimbat în apel static corect:
```php
$tname = str_ireplace(" ", "", testproject::getName($db, $tproject_id));
```
Alternative respinse: wrapper de instanță nou în clasa `testproject` (dublă
mentenanță pentru același query) sau duplicarea SQL-ului în BFF (pierde sursa
unică de adevăr). Soluția aleasă reproduce 1:1 fix-ul deja validat din
`api/keywords/index.php:138`, deci comportamentul este consistent între cele
două BFF-uri și identic cu legacy `platformsExport.php doExport()`
(`<NumeProiectFărăSpații>-platforms.xml`).

**Stare.** Fix livrat pe branch-ul `sebiboga` (commit `1c2fac63b`). Verificat
cap-coadă: export fără `filename` → HTTP 200 cu
`Content-Disposition: filename="DemoProject-platforms.xml"`; export cu
`filename=custom.xml` → numele custom păstrat; export cu platforme definite
produce documentul ADODB_XML legacy (`<platforms><platform>…<is_open>`); butonul
**Export Platforms** din UI funcționează (HTTP 200); rutele surioare
(list/create/delete) re-testate OK; Event Viewer fără erori noi.

