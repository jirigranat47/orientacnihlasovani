# Orientační hlasování sněmu / skupiny (PHP + SQLite)

Jednoduchá, rychlá webová aplikace pro orientační a preferenční hlasování ve skupině (optimalizovaná pro **100+ souběžně hlasujících osob** v reálném čase). Nevyžaduje žádný externí databázový server (MySQL/PostgreSQL) – využívá jedno-souborovou databázi **SQLite v režimu WAL** (`data/voting.sqlite`).

> **Připraveno k okamžitému použití:** Součástí repozitáře je již připravená databáze `data/voting.sqlite`, která ve výchozím stavu obsahuje **dvě předpřipravené otázky** k předsněmové diskuzi (z materiálů ve složce `otazky/`):
> 1. **Pořadí preferencí: 6 variant stanovení delegátů VSJ** *(preferenční hlasování o pořadí variant)*
> 2. **Pravidelné jmenování starosty: Výběr nejvhodnější varianty** *(výběr z navržených variant)*

---

## 1. Spuštění a adresy aplikace

* **Hlasovací stránka pro účastníky (mobily / notebooky):**
  * `http://localhost/orientacnihlasovani/` (nebo `index.php`)
  * Při hlasování v místnosti přes Wi-Fi použijte lokální IP adresu počítače (např. `http://192.168.1.50/orientacnihlasovani/`). V administraci lze jedním klikem zobrazit **QR kód** pro snadné načtení telefonem.
* **Administrace a prezentace výsledků na plátně:**
  * `http://localhost/orientacnihlasovani/admin.php`
  * **Výchozí PIN kód:** `2027` *(lze změnit přímo v levém panelu administrace)*.

---

## 2. Jak funguje identifikace hlasujícího (`localStorage`)

1. Při prvním otevření stránky `index.php` si prohlížeč účastníka vygeneruje unikátní anonymní identifikátor (`crypto.randomUUID()`) a uloží jej do `localStorage` pod klíčem `orientacni_hlasovani_voter_id`.
2. V pravém horním rohu obrazovky účastník vidí svůj zkrácený kontrolní kód (např. `ID: 8F3A1B`).
3. **Možnost změny hlasu:** Dokud je hlasování aktivní, účastník může své pořadí preferencí nebo vybranou možnost kdykoliv upravit a znovu odeslat. V SQLite databázi je primární klíč `(poll_id, voter_id)` – při změně volby se tedy prostřednictvím atomického `UPSERT` (`ON CONFLICT DO UPDATE`) přepíše jeho původní hlas na nový (stále platí **1 zařízení = 1 hlas**).

---

## 3. Typy otázek a vyhodnocení preferencí

Při vytváření nebo úpravě otázky v administraci (`+ Nová otázka` / `✏️ Upravit otázku`) můžete zvolit jeden ze 4 typů hlasování:

1. **🔢 Pořadí preferencí – seřadit všechny varianty (`ranking`)**
   * Účastník seřadí všechny nabízené možnosti od `1.` (nejvyšší preference) po `N.` místo.
   * **Ovládání:** Přetažením položky myší nebo prstem za ikonu `⠿` (během tažení se zobrazuje živý box **`➜ Umístit na X. místo`**) nebo klikáním na šipky `▲` / `▼`.
2. **☑️ Výběr a pořadí přijatelných variant (`ranking_partial`)**
   * Účastník si kliknutím na `+` vybere pouze ty varianty, se kterými souhlasí, a ty následně seřadí podle preference.
3. **🔘 Klasická volba – výběr 1 možnosti (`single`)**
   * Po kliknutí na možnost se hlas okamžitě uloží.
4. **✅ Více možností – zaškrtávací (`multi`)**
   * Účastník může zaškrtnout libovolný počet možností.

### Jak se počítají výsledky u pořadí preferencí:
* **Bodování dle umístění:** Při $N$ možnostech získává 1. místo $N$ bodů, 2. místo $N-1$ bodů, …, poslední místo $1$ bod (nezařazená varianta u částečného výběru získává $0$ bodů). Celkové pořadí určí součet bodů od všech hlasujících (při rovnosti bodů rozhoduje vyšší počet 1. míst).
* **Statistika umístění:** U každé možnosti vidíte průměrné pořadí, procento podpory (kolik lidí ji zařadilo) a přesný počet umístění na **1. místě**, 2. místě, 3. místě atd.

---

## 4. Šablona návrhu usnesení a zástupné značky

U každé otázky můžete v administraci (`✏️ Upravit otázku`) nastavit **Šablonu návrhu usnesení**.
* **Pokud pole necháte prázdné:** Tmavý box s návrhem usnesení se pod výsledky vůbec nezobrazí.
* **Pokud šablonu vyplníte:** Pod výsledky hlasování se automaticky vygeneruje finální text usnesení s tlačítkem pro zkopírování do schránky.

### Dostupné zástupné značky v šabloně:
| Značka | Co se na její místo automaticky dosadí |
| :--- | :--- |
| `{jednotka}` | Název jednotky nastavený v levém panelu administrace |
| `{vysledek}` | Seznam možností seřazený podle výsledku hlasování oddělený čárkou |
| `{vitez}` | Pouze 1. vítězná možnost |

**Příklady šablon (v administraci je můžete vložit jedním kliknutím):**
* **Pro předsněmovou diskuzi – Stanovení delegátů VSJ:**
  > `„Sněm jednotky ({jednotka}) se zabýval otázkou volby delegátů na budoucí Valné sněmy Junáka – českého skauta a došel k následujícímu závěru: Sněm se seznámil s možnostmi uvedenými v předsněmových materiálech a z uvedených variant souhlasí s potenciálním schválením následujících variant (v uvedeném pořadí dle preferencí): {vysledek}.“`
* **Pro předsněmovou diskuzi – Pravidelné jmenování starosty:**
  > `„Sněm jednotky ({jednotka}) shledává jako nejvhodnější pro jmenování starosty variantu: {vitez}.“`
* **Pro obecné hlasování:**
  > `„Sněm jednotky ({jednotka}) po projednání schvaluje variantu: {vitez} (celkové pořadí preferencí: {vysledek}).“`

---

## 5. Struktura souborů

* `index.php` – Uživatelská hlasovací aplikace
* `admin.php` – Administrace, správa otázek, nápověda a prezentační režim na plátno
* `api.php` – JSON REST endpointy pro synchronizaci v reálném čase
* `db.php` – Inicializace SQLite databáze (WAL režim), výchozí otázky a výpočet výsledků
* `data/voting.sqlite` – Souborová databáze SQLite (chráněná přes `data/.htaccess`). Pokud chcete celou aplikaci uvést do továrního nastavení, stačí smazat soubor `data/voting.sqlite`.
