# Search Test Cases - Jolly (OR) Search

## Overview
The "Search Test Cases" screen (`search/searchView.html`) now includes a **Jolly** (free-text OR) search field. This replicates legacy TestLink 1.9.20 behavior allowing a single keyword to be matched across multiple text fields with OR logic.

## Jolly Field
The Jolly input searches for the term across:
- Test case title/name
- Summary
- Preconditions
- Steps (actions)
- Expected results

All terms are combined with OR within this group; other search criteria (ID, version, status, importance, keywords, custom fields, dates, etc.) remain AND-ed with the results.

## Implementation
- **BFF**: `api/search/index.php` extended to accept `jolly` parameter and build an OR-group across the five text fields when non-empty
- **UI**: `gui/templates/search/searchView.html` includes the Jolly input field in the form grid with i18n labels and tooltip
- **i18n**: Added `search.jolly` and `search.jollyHint` keys to all locale bundles
