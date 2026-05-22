# Specifications — BBV Programma Templates

## REQ-BBV-001: Hiërarchie Integriteit

**Scenario 1: Delete Programma with child Doelen**

GIVEN een Programma "Sociaal Domein" met 3 onderliggende Doelen ("Jeugdzorg", "Ouderenzorg", "Integratiebeleid")
WHEN een Administrator op de delete-button klikt
THEN het systeem MUST de verwijdering blokkeren, MUST een HTTP 400 foutmelding retourneren met de tekst "Cannot delete Programma 'Sociaal Domein': contains 3 active Doelen", en MUST links tonen naar de blokkerende Doelen zodat de gebruiker ze kan archiveren

**Scenario 2: Delete Doel with child Indicatoren**

GIVEN een Doel "Jeugdzorg" met 4 Indicatoren
WHEN een Administrator probeert het Doel te verwijderen
THEN het systeem MUST de verwijdering blokkeren met foutmelding "Cannot delete Doel: contains 4 Indicatoren"

**Scenario 3: Delete Doel without Indicatoren**

GIVEN een Doel "Jeugdzorg" zonder Indicatoren of Maatregelen
WHEN een Administrator klikt op delete
THEN het systeem MUST het Doel verwijderen en HTTP 204 No Content retourneren

## REQ-BBV-002: Verplichte Beleidsindicatoren

**Scenario 1: Doel created without required indicators**

GIVEN een Doel "Kinderopvang" onder programma "Sociaal Domein", GIVEN dat BZK voorschrijft 2 verplichte beleidsindicatoren voor dit doeltype
WHEN een gebruiker het Doel opslaat zonder deze indicatoren aan te maken
THEN het systeem MUST een modal tonen met titel "Verplichte Beleidsindicatoren Ontbreekt", listing de 2 verplichte indicatoren (naam, code, bron), en MUST een knop "Maak nu aan" aanbieden

**Scenario 2: Auto-create required indicators**

GIVEN de modal toont "BZK-KO-001: Percentage kinderen in formele kinderopvang" en "BZK-KO-002: Percentage kinderen in informele opvang"
WHEN gebruiker klikt "Maak nu aan"
THEN het systeem MUST beide Indicatoren met standaard definities van BZK aanmaken (bron="waarstaatjegemeente", berekeningswijze=BZK-voorgegeven), MUST nulmeting en streefwaarde overnemen uit BZK-configuratie, en MUST een bevestigingsmelding tonen: "2 indicatoren aangemaakt"

**Scenario 3: Doel created with all required indicators**

GIVEN dezelfde Doel "Kinderopvang" opgeslagen met beide BZK-indicatoren reeds aanwezig
WHEN gebruiker klikt opslaan
THEN het systeem MUST de Doel opslaan zonder waarschuwing

## REQ-BBV-003: Template Installatie

**Scenario 1: Install G4 template in empty tenant**

GIVEN een gemeente-tenant zonder bestaande Programma's / Doelen / Indicatoren
GIVEN de Administrator opent de TemplateInstaller en selecteert "G4 Gemeenten Template v1.0"
WHEN de Administrator op "Installeer" klikt
THEN het systeem MUST atomair (in één transactie) alle Template-records klonen:
- 4 Programma's (Sociaal Domein, Ruimte, Economie, Bestuur)
- 10 Doelen (2–3 per Programma)
- 35 Indicatoren (3–5 per Doel)
- 4 IV3Mapping-records (één per Programma)
THEN het systeem MUST een installatierapport tonen met
- "✓ 4 Programma's aangemaakt"
- "✓ 10 Doelen aangemaakt"
- "✓ 35 Indicatoren aangemaakt"
- "✓ 4 IV3-mappings geconfigureerd"
En het rapport MUST voetteksten bevatten: "Alle gegevens zijn nu klaar. U kunt doelen gaan uitwerken en indicatorgegevens importeren."

**Scenario 2: Install fails mid-way**

GIVEN de installatie is halverwege en er treedt een database-error op
WHEN de fout wordt afgehandeld
THEN het systeem MUST de gehele transactie terugrollen, MUST geen partiële Programma's/Doelen/Indicatoren achterlaten, en MUST een foutmelding retourneren met retry-opties

**Scenario 3: Install when template already exists**

GIVEN een tenant met reeds geïnstalleerde "G4 Template v1.0"
WHEN de Administrator probeert dezelfde template opnieuw te installeren
THEN het systeem MUST een waarschuwing tonen: "Dit template is al geïnstalleerd op [datum]. Weet u zeker?" met opties "Annuleren" en "Vervangen"

## REQ-BBV-004: Template Versie-Upgrade

**Scenario 1: Upgrade available, show differences**

GIVEN een tenant met geïnstalleerde "G4 Template v1.0"
GIVEN BZK heeft nieuwe verplichte beleidsindicatoren gepubliceerd en template v1.1 is beschikbaar
WHEN Administrator navigeert naar Settings → Template Upgrade
THEN het systeem MUST tonen: "Upgrade beschikbaar van v1.0 naar v1.1"
THEN het systeem MUST per verschil een checkbox tonen met:
- Indicator BZK-JZ-005 (naam): "Jongeren in arbeidsprogramma's"
  [NIEUW in v1.1]
  ☐ Accepteren
- Programma "Sociaal" (streefwaarde indicator BZK-JZ-001): "1000" → "1200"
  [GEWIJZIGD in v1.1]
  ☐ Accepteren

**Scenario 2: Selective upgrade with local customizations**

GIVEN Administrator heeft na v1.0-installatie lokaal indicator BZK-JZ-001's streefwaarde gewijzigd van "1000" naar "950" (aangepast naar lokale situatie)
GIVEN v1.1 update stelt streefwaarde in op "1200"
WHEN Administrator selecteert deze indicator-wijziging en klikt "Accepteren"
THEN het systeem MUST een modal tonen: "Waarschuwing: U hebt dit veld lokaal gewijzigd naar 950. Weet u zeker dat u naar 1200 wilt gaan?"
THEN Administrator kan "Behoud mijn versie (950)" of "Accepteer update (1200)" kiezen
THEN het systeem MUST de gekozen optie toepassen

**Scenario 3: Upgrade rejected, old version kept**

GIVEN Administrator klikt "Weigeren" voor een indicator-wijziging
THEN het systeem MUST die wijziging niet toepassen, MUST de huidige waarde behouden, en MUST dit vastleggen in de changelog

## REQ-BBV-005: IV3 Mapping en Export

**Scenario 1: Generate IV3 export**

GIVEN een gemeente-tenant met 4 Programma's en IV3Mapping's:
- Programma "Sociaal Domein" → IV3-taakveld "15.01.01" (100%)
- Programma "Ruimte" → IV3-taakveld "33.01.01" (100%)
- Programma "Economie" → IV3-taakveld "32.01.01" (100%)
- Programma "Bestuur" → IV3-taakveld "01.01.01" (100%)
GIVEN financeq geeft voor periode 2024 terug:
- Taakveld 15.01.01: EUR 12.000.000 begroting, EUR 11.500.000 realisatie
- Taakveld 33.01.01: EUR 8.000.000 begroting, EUR 7.800.000 realisatie
- Taakveld 32.01.01: EUR 3.000.000 begroting, EUR 3.100.000 realisatie
- Taakveld 01.01.01: EUR 2.000.000 begroting, EUR 2.050.000 realisatie
WHEN Administrator klikt op "Export IV3"
THEN het systeem MUST valideren dat mapping-percentages per programma 100% bedraagt
THEN het systeem MUST een XML/CSV-bestand genereren in BZK-voorgeschreven format met:
- Taakveld, Categorie, Begroting, Realisatie per rij
THEN het systeem MUST het bestand downloaden als "iv3_export_2024_gemeente.xml"

**Scenario 2: Mapping sums to <100%**

GIVEN een Programma "Sociaal" met IV3Mapping's:
- 50% taakveld "15.01.01"
- 40% taakveld "15.02.01"
- (totaal 90%, niet 100%)
WHEN Administrator klikt "Export IV3"
THEN het systeem MUST de export blokkeren, MUST HTTP 400 retourneren met foutmelding: "Programma 'Sociaal Domein' mapping sums to 90% (expected 100%). Please adjust IV3 allocations."

**Scenario 3: Unmapped programma's**

GIVEN een Programma "Experimentele Projecten" zonder IV3Mapping
WHEN Administrator klikt "Export IV3"
THEN het systeem MUST de export blokkeren, MUST foutmelding geven: "Programma 'Experimentele Projecten' heeft geen IV3-mapping. Alle programma's moeten gemapped zijn."

## REQ-BBV-006: Indicator-Metingen via OpenConnector

**Scenario 1: Sync IndicatorMeting from waarstaatjegemeente**

GIVEN een Indicator "Jongeren in behandeling" (bron="waarstaatjegemeente", code="BZK-JZ-001")
GIVEN openconnector plant een wekelijkse sync voor deze indicator
WHEN openconnector de meting ophaalt (e.g., waarde=1250 voor periode 2024-Q4)
THEN openconnector MUST via webhook POST `/api/bbv/indicatoren/{id}/metingen` de IndicatorMeting aanmaken met:
- waarde: 1250
- periode: "2024-Q4"
- geverifieerdDoor: "system-openconnector"
- geverifieerdOp: [huidige timestamp]
THEN mydash MUST een IndicatorMeting-record aanmaken in OpenRegister

**Scenario 2: Notification on variance**

GIVEN Indicator "Jongeren in behandeling" met streefwaarde 1500, laatste meting 1250
GIVEN konfiguratie: notify als waarde <80% van streefwaarde afwijkt (threshold=20%)
GIVEN nieuwe meting ingekomen: 1180 (waarde = 78,7% van streefwaarde, afwijking >20%)
WHEN sync compleet
THEN het systeem MUST een Nextcloud-notificatie naar de Doel-eigenaar sturen: "Indicator 'Jongeren in behandeling' wijkt 21% af van streefwaarde. Huidige waarde: 1180, streefwaarde: 1500."

**Scenario 3: Sync fails, no data loss**

GIVEN sync van CBS API faalt vanwege timeout
WHEN openconnector retry-mechanisme uitgeput
THEN het systeem MUST:
- Geen partiële IndicatorMeting aanmaken
- Een notification naar doel-eigenaar sturen: "Sync voor indicator BZK-JZ-001 is mislukt. Vorige sync was op [datum]."
- Bestaande metingen ongewijzigd laten

## REQ-BBV-007: Maatregel ↔ Planix Koppeling

**Scenario 1: Link Maatregel to planix**

GIVEN een Maatregel "Opschaling jeugdzorg capaciteit" (startdatum 2025-03-01, einddatum 2025-12-31, geraamdBudget EUR 250.000)
WHEN gebruiker klikt op "Plan in planix"
THEN het systeem MUST:
- planix API aanroepen: POST /projects
- Een planix Project aanmaken met:
  - name: "Opschaling jeugdzorg capaciteit"
  - description: "[inhoud van Maatregel.omschrijving]"
  - startDate: 2025-03-01
  - endDate: 2025-12-31
  - budget: 250000
- De planix projectId opslaan in Maatregel.planixProjectId
THEN het scherm MUST beconfirmen: "✓ Maatregel 'Opschaling jeugdzorg capaciteit' is nu gepland in planix"

**Scenario 2: Sync status to planix**

GIVEN Maatregel is gelinkt aan planix project (planixProjectId = "prj-uuid")
GIVEN status in mydash is "in_uitvoering"
GIVEN planix project-status wordt gewijzigd naar "afgerond" in planix
WHEN mydash haalt de status op via planix API (polling of webhook)
THEN mydash MUST de Maatregel-status naar "afgerond" synchroniseren
THEN het systeem MUST een bevestigingsnotificatie naar de Maatregel-trekker sturen: "Maatregel status updated to 'afgerond' from planix"

**Scenario 3: Link fails, planix unavailable**

GIVEN planix API is offline
WHEN gebruiker klikt "Plan in planix"
THEN het systeem MUST een foutmelding tonen: "Planix is momenteel niet bereikbaar. Probeer later opnieuw."
THEN de Maatregel MUST niet gewijzigd worden (planixProjectId blijft NULL)

## REQ-BBV-008: Begroting ↔ Financeq Koppeling

**Scenario 1: Realisatie-sync from financeq**

GIVEN een Programma "Sociaal Domein" met IV3-mapping:
- 100% taakveld "15.01.01"
GIVEN financeq heeft voor 2024:
- Taakveld 15.01.01: geraamd EUR 10.000.000, gerealiseerd EUR 9.500.000
WHEN Administrator klikt op "Sync met financeq" (handmatig)
THEN het systeem MUST:
- financeq API query: GET /taakvelden/15.01.01/begroting-realisatie?jaar=2024
- Antwoord ontvangen: begroting=10000000, realisatie=9500000
- Programma-record updaten met geraamdBudget=10000000, gerealiseerdBudget=9500000
- Realisatie-percentage berekenen: 95% (9.500.000 / 10.000.000)
THEN het systeem MUST een bevestigingsmelding tonen: "✓ Begroting gesynced: EUR 10M begraamd, EUR 9,5M gerealiseerd (95%)"

**Scenario 2: Afwijking signalering**

GIVEN Programma "Sociaal Domein" realisatie is 85% (afwijking 15% onder begroting)
GIVEN konfiguratie: signaleer afwijking als >10%
WHEN sync compleet
THEN het systeem MUST een notification naar portefeuillehouder sturen: "Programma 'Sociaal Domein' wijkt 15% af van begroting. Begraamd: EUR 10M, Gerealiseerd: EUR 8,5M"

**Scenario 3: Multi-taakveld Programma**

GIVEN een Programma "Ruimte & Duurzaamheid" met IV3-mappings:
- 60% taakveld "33.01.01"
- 40% taakveld "33.02.01"
GIVEN financeq geeft:
- Taakveld 33.01.01: EUR 6M begraamd, EUR 5.7M gerealiseerd
- Taakveld 33.02.01: EUR 4M begraamd, EUR 4.2M gerealiseerd
WHEN sync
THEN het systeem MUST verdelen over programma:
- Programma geraamdBudget = (6M × 60%) + (4M × 40%) = 3,6M + 1,6M = 5,2M
- Programma gerealiseerdBudget = (5,7M × 60%) + (4,2M × 40%) = 3,42M + 1,68M = 5,1M
THEN realisatie-percentage = 98%

## REQ-BBV-009: Hiëarchie-Validatie

**Scenario 1: Doel must belong to a Programma**

GIVEN een Doel-creatie-formulier
WHEN geen Programma geselecteerd is
THEN het formulier MUST de submit-button grayed-out laten, MUST een helptext tonen: "Selecteer een Programma waaraan deze Doel hoort"

**Scenario 2: Indicator must belong to a Doel**

GIVEN een Indicator-creatie-formulier
WHEN geen Doel geselecteerd
THEN het formulier MUST validatiemelding tonen: "Doel is verplicht"

**Scenario 3: Maatregel must belong to a Doel**

GIVEN een Maatregel-creatie-formulier
WHEN geen Doel geselecteerd
THEN het formulier MUST validatiemelding tonen: "Doel is verplicht"

## REQ-BBV-010: Audit Trail

**Scenario 1: Track Programma changes**

GIVEN een Programma "Sociaal Domein" gemaakt op 2025-01-15 door gebruiker alice@gemeente.nl
GIVEN de status wordt gewijzigd naar "vastgesteld" op 2025-01-20 door bob@gemeente.nl
WHEN gebruiker klikt op "Audit Trail" tab in Programma-detail
THEN het systeem MUST tonen:
- 2025-01-15 08:00 — alice@gemeente.nl created Programma "Sociaal Domein" (status=concept)
- 2025-01-20 14:30 — bob@gemeente.nl changed status from "concept" to "vastgesteld"
THEN elk change-record MUST voor/na waarden tonen (before/after snapshots)

**Scenario 2: Export audit trail**

GIVEN gebruiker is admin
WHEN gebruiker klikt "Export audit trail"
THEN het systeem MUST een CSV bestand genereren met alle wijzigingen (user, timestamp, entity, field, old value, new value) voor de huidige periode
