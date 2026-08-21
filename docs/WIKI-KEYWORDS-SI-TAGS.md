# Keywords și Tags - Organizare Flexibilă 🏷️

## Ce sunt Keywords/Tags?

**Keywords** sunt etichete care ajută să organizezi și să grupezi test case-uri pe bază de caracteristici:

```
Test Case: TC-050 (Transfer Bani)

Keywords:
├─ feature: transfers
├─ priority: critical
├─ component: payments
├─ test-type: functional
├─ platform: mobile
└─ area: financial
```

## Beneficii Keywords

```
Fără Keywords:
├─ 500 test cases în repository
├─ Greu de găsit
├─ Nu știu care sunt mobile
├─ Nu știu care sunt security
└─ Dezorganizat

Cu Keywords:
├─ Filtrează: "mobile" → 80 cases
├─ Filtrează: "security" → 50 cases
├─ Filtrează: "payments" → 30 cases
├─ Filtrează: "mobile AND payments" → 10 cases
└─ Rapid și eficient
```

## Creare Keywords

**Cine poate:** Administrator, Project Manager

### Pași:

1. Mergi la **Proiect → Keywords**
2. Apasă **Adaugă Keyword Nou**
3. Introdu:
   - **Titlu:** feature, priority, component, etc
   - **Descriere:** Cărui e folosit
4. Apasă **Salvează**

### Exemplu:

```
Keyword: mobile
Descriere: Tests for mobile devices (iOS, Android)

Keyword: payments
Descriere: Payment processing and transactions

Keyword: security
Descriere: Security-related tests

Keyword: api
Descriere: REST API integration tests

Keyword: performance
Descriere: Performance and load tests
```

## Atribuire Keywords Test Cases

### Metoda 1: La Criere Test Case

```
Crezi Test Case: TC-050 (Transfer Bani)
├─ Keywords: payments, security, critical, mobile
└─ Salvezi
```

### Metoda 2: Edit Test Case Existent

1. **Test Case → Editează**
2. **Keywords:** Adaugă
3. Selectează/Introdu keywords
4. **Salvează**

## Utilizare Keywords în Planuri

### Filtrare la Adăugare Test Cases:

```
Plan: v1.5 Mobile Testing

Filtrează: keyword = "mobile"
Rezultat: 80 test cases cu tag-ul "mobile"

Adaugă: Toți cele 80
```

### Raport per Keyword:

```
Report: Coverage by Keyword

mobile: 80 cases (85% pass)
payments: 30 cases (90% pass)
security: 50 cases (88% pass)
api: 40 cases (92% pass)
performance: 20 cases (75% pass)
```

## Search cu Keywords

Căutare avansată folosind keywords:

```
Căutare: keyword:mobile AND keyword:payments
Rezultat: 10 test cases (mobile payment tests)

Căutare: keyword:security NOT keyword:api
Rezultat: 15 test cases (non-API security tests)

Căutare: keyword:(mobile OR tablet) AND priority:high
Rezultat: 25 high-priority mobile/tablet tests
```

## Organizare per Proiect

Fiecare proiect poate avea alți keywords:

```
Banking App:
├─ Keywords: payments, transfers, loans, security, compliance
└─ 500 test cases

E-Commerce:
├─ Keywords: checkout, inventory, shipping, analytics, integration
└─ 800 test cases

Mobile App:
├─ Keywords: ios, android, ui, performance, offline-mode
└─ 400 test cases
```

## Hierarhie Keywords

Poți crea structură:

```
Main Category: Feature
├─ auth (login, logout, password)
├─ payments (transfer, checkout, invoice)
├─ reports (generation, export, analytics)
└─ admin (users, settings, audit)

Main Category: Platform
├─ mobile (ios, android)
├─ web (desktop, tablet)
└─ api (rest, graphql)

Main Category: Severity
├─ critical
├─ high
├─ medium
└─ low
```

## Bulk Operations cu Keywords

### Atribui Keyword la Multiple Cases:

1. **Selectează 10+ test cases**
2. **Bulk Action:** Add Keyword
3. Selectează keyword
4. **Apply to All**

```
Rezultat: 10 test cases get tag "mobile"
```

### Căutare Rapid:

```
Click: Keyword "payments"
Rezultat: Toți test cases cu "payments"
```

## Statistics per Keyword

Raport automat:

```
Keyword Statistics:

mobile:
├─ Test Cases: 80
├─ Plans Used In: 5
├─ Last Updated: 2024-02-10
├─ Coverage: 85%
└─ Pass Rate: 92%

payments:
├─ Test Cases: 30
├─ Plans Used In: 8
├─ Last Updated: 2024-02-12
├─ Coverage: 100%
└─ Pass Rate: 95%

security:
├─ Test Cases: 50
├─ Plans Used In: 3
├─ Last Updated: 2024-02-08
├─ Coverage: 90%
└─ Pass Rate: 88%
```

## Recommended Keyword Structure

```
TYPE Keywords (What kind of test):
- functional
- integration
- security
- performance
- regression
- smoke
- acceptance

PRIORITY Keywords (How important):
- critical
- high
- medium
- low

PLATFORM Keywords (Where runs):
- web
- mobile
- ios
- android
- desktop
- tablet

COMPONENT Keywords (What area):
- auth
- payments
- reporting
- notifications
- admin
- api

STATUS Keywords (Current state):
- automated
- manual
- deprecated
- needs-review
- ready-for-prod
```

## Best Practices

✅ **DO:**
- **Consistent naming:** lowercase, no spaces (use-hyphens)
- **Meaningful keywords:** Specific and useful
- **Multiple per case:** 3-5 keywords ideal
- **Document:** Ce înseamnă fiecare
- **Review:** Periodical audit

❌ **DON'T:**
- Nu folosi vag keywords (misc, test, other)
- Nu amesteca diferite sisteme
- Nu ștergi în folosință
- Nu crezi prea mulți (>100)

---

**Keywords: Simple dar powerful pentru organizare! 🎯**

---

## Fixed: ArgumentCountError 500 în keywords BFF la `testproject::getName()` (Issue #531)

**Simptom.** `GET /api/keywords/index.php?tproject_id=<id real>` întorcea HTTP 500
(`ArgumentCountError: Too few arguments to function testproject::getName()`) de
fiecare dată când proiectul exista, iar ecranul **Keyword Management** nu își
încărca tabelul. Cererile cu `tproject_id=0` treceau prin validarea timpurie
(HTTP 400), ceea ce a mascat bug-ul.

**Cauza root.** `api/keywords/index.php` (linia ~138) apela
`$tproject_mgr->getName($tproject_id)` — apel de instanță — dar în acest
codebase `testproject::getName(&$dbh, $id)` este **static** și primește mai
întâi handler-ul de bază de date; apelul de instanță transmite un singur
argument, deci PHP 8 aruncă `ArgumentCountError`.

**Abordarea fix-ului.** Apelul a fost schimbat în apel static corect:
```php
'name' => testproject::getName($db, $tproject_id),
```
Alternative respinse: adăugarea unei metode de instanță wrapper (dublă
mentenanță pentru același query) sau duplicarea logicii SQL în BFF (pierde
sursa unică de adevăr din clasa `testproject`). Notă: același tipar greșit
există și în `api/platforms/index.php:369` (export fără parametrul `filename`)
și a fost raportat separat în issue #534.

**Stare.** Corecția a ajuns pe branch-ul `sebiboga` prin commit-ul `e9da5834c`
(înlocuind apelul greșit introdus în `626e03ac5`). Rularea curentă a re-verificat
cap-coadă: listare HTTP 200 cu numele proiectului, creare/ștergere keyword,
ecranul UI încărcat corect, Event Viewer curat (un E_WARNING pre-existent,
nelegat de acest fix, a fost raportat separat în issue #533).

