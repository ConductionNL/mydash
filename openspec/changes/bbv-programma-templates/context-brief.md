status: draft

# BBV Programma Templates

## Placement & Information Architecture

**Placement type:** `SUB_PAGE` — Sub-page beneath a top-level menu entry. Renders as a page inside the parent surface (usually reachable via a router child route or a tab on the parent index page).

**Lives at:** Templates / (root)

**Rationale:** Sector starter content  
_Source: /tmp/ia-mydash-openregister.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

Provide a first-class data model and seed-template library for the Nederlandse gemeentelijke Programmabegroting-structuur (BBV: Besluit Begroting en Verantwoording provincies en gemeenten) inside mydash so that gemeenten can stand up a programma / doel / indicator hierarchy in minutes instead of weeks. Every gemeente in Nederland is wettelijk verplicht een programmabegroting op te stellen die de gemeentelijke taken indeelt in programma's (sociaal domein, ruimte, bestuur, openbare orde, ...), met per programma één of meer doelen en per doel meetbare indicatoren (vaak gekoppeld aan de wettelijke "Beleidsindicatoren" uit waarstaatjegemeente.nl).

Vandaag bouwt elke gemeente die structuur opnieuw op in Excel of in een lokaal BI-tool, vaak afwijkend van de buurgemeente, waardoor benchmarking lastig is en de Provinciale toezichthouder de aanlevering moeilijk kan vergelijken. Tegelijkertijd is de BBV-taxonomie sterk gestandaardiseerd: het Ministerie van BZK publiceert de verplichte beleidsindicatoren, de IV3-rubrieken (Informatie voor Derden) zijn vastgesteld, en de meeste gemeenten hanteren een vergelijkbare programma-indeling op hoofdlijnen.

Deze spec introduceert een Programma / Doel / Indicator / Maatregel datamodel dat aansluit op BBV en IV3, seed-templates voor de gangbare programma-indelingen (G4, M50, kleine gemeenten, provincies, waterschappen), één-klik installatie zodat een gemeente een template kiest en alle programma's, doelen en standaard-indicatoren in één keer aanmaakt, en koppelingen naar financeq (voor de begrotings- en realisatie-cijfers per programma) en planix (voor de planning van maatregelen onder een doel). De seed-data wordt centraal beheerd in openregister zodat alle gemeenten dezelfde versie krijgen en updates (bv. een nieuwe beleidsindicator uit BZK) worden uitgerold.

## Data Model

**Programma**: programmaId, code, naam, omschrijving, portefeuillehouder (FK persoon), themaCluster (sociaal, ruimte, bestuur, veiligheid, economie, financien, milieu), volgorde, jaar, status (concept, vastgesteld, in_uitvoering, verantwoord).

**Doel**: doelId, programmaId, code, naam, omschrijving, beoogdMaatschappelijkEffect, eigenaar (FK persoon), startjaar, eindjaar, status.

**Indicator**: indicatorId, doelId, code, naam, eenheid, bron (bv. waarstaatjegemeente, CBS, eigen meting), berekeningswijze, isVerplichteBeleidsindicator (boolean), nulmeting, streefwaarde, streefjaar.

**IndicatorMeting**: indicatorId, periode, waarde, geverifieerdDoor, geverifieerdOp, opmerking.

**Maatregel**: maatregelId, doelId, naam, omschrijving, trekker (FK persoon), startdatum, einddatum, status, geraamdBudget, gerealiseerdBudget.

**IV3Mapping**: programmaId, iv3Taakveld (code uit BZK-lijst), iv3Categorie, percentage (verdeling als programma over meerdere taakvelden valt).

**ProgrammaTemplate**: templateId, naam, doelgroep (G4, M50, klein, provincie, waterschap, nldesign-standaard), versie, geldigVanaf, geldigTotEnMet, brongemeente (optioneel), licentie, taal.

**TemplateProgramma / TemplateDoel / TemplateIndicator**: skeleton-records that the install step clones into the gemeente's tenant.

## Requirements

### REQ-BBV-001: Hiërarchie integriteit
GIVEN een programma met onderliggende doelen, WHEN een gebruiker het programma probeert te verwijderen, THEN de systeem MUST de verwijdering blokkeren tenzij alle doelen en indicatoren eerst zijn gearchiveerd, en MUST een duidelijke foutmelding tonen met links naar de blokkerende records.

### REQ-BBV-002: Verplichte beleidsindicatoren
GIVEN een Doel binnen een programma waarvoor BZK verplichte beleidsindicatoren voorschrijft, WHEN de gebruiker het doel opslaat zonder dat alle verplichte indicatoren zijn aangemaakt, THEN het systeem MUST een waarschuwing tonen met de ontbrekende indicatoren en MUST de mogelijkheid bieden ze met één klik aan te maken op basis van de BZK-definitie.

### REQ-BBV-003: Template installatie
GIVEN een gemeente-tenant zonder bestaande programmastructuur, WHEN een beheerder een ProgrammaTemplate kiest en op "installeer" klikt, THEN het systeem MUST alle Template-records klonen naar de tenant, MUST de juiste IV3-mapping meekopiëren, MUST de installatie als één transactie uitvoeren (atomair), en MUST een installatierapport tonen met aantallen aangemaakte programma's, doelen, indicatoren en maatregel-skeletten.

### REQ-BBV-004: Template versie-upgrade
GIVEN een tenant met een geïnstalleerde template versie X, WHEN een nieuwe versie Y beschikbaar komt (bv. door een BZK-update van de verplichte beleidsindicatoren), THEN het systeem MUST de verschillen tonen, MUST per wijziging een keuze geven (overnemen, overslaan, eigen versie behouden), en MUST nooit lokaal aangepaste teksten of waarden overschrijven zonder expliciete bevestiging.

### REQ-BBV-005: IV3 koppeling en aanlevering
GIVEN programma's met een IV3Mapping, WHEN de gemeente een IV3-export genereert voor de Provinciale toezichthouder of CBS, THEN het systeem MUST de mapping toepassen op de financeq-cijfers, MUST controleren dat de som per programma 100% bedraagt, en MUST een exportbestand produceren in het door BZK voorgeschreven formaat.

### REQ-BBV-006: Indicator-metingen via openconnector
GIVEN een indicator met bron "waarstaatjegemeente" of "CBS", WHEN het systeem een geplande sync uitvoert via openconnector, THEN het MUST de meest recente meting ophalen, MUST een IndicatorMeting-record aanmaken met geverifieerdDoor = "systeem", en MUST de eigenaar van het doel notificeren als de waarde sterk afwijkt van de streefwaarde.

### REQ-BBV-007: Maatregel <-> planix koppeling
GIVEN een Maatregel onder een doel, WHEN de trekker op "plannen" klikt, THEN het systeem MUST een planix-project of taakset aanmaken die gelinkt is aan de maatregel, MUST de status van de maatregel synchroniseren met de planning, en MUST de begrotings- en realisatie-cijfers terugkoppelen naar de maatregel.

### REQ-BBV-008: Begroting <-> financeq koppeling
GIVEN een programma met een toegewezen budget, WHEN financeq de realisatie-cijfers per IV3-taakveld bijwerkt, THEN het systeem MUST de cijfers via de IV3Mapping verdelen over de programma's, MUST per programma een realisatie-percentage berekenen, en MUST een afwijking boven de drempel signaleren aan de portefeuillehouder.

## Standards

- BBV (Besluit Begroting en Verantwoording provincies en gemeenten).
- IV3 — Informatie voor Derden taakveldenlijst.
- Verplichte beleidsindicatoren BZK (jaarlijks bijgewerkt).
- Waarstaatjegemeente.nl data-bron-specificaties.
- StUF-BG / StUF-ZKN niet direct relevant; wel CBS Open Data API.
- DCAT-AP-DONL voor open-data publicatie van de indicatoren.

## Cross-app

- **mydash bbv-programma-tree**: visualisatie-component die op deze data draait.
- **financeq**: bron van begroting en realisatie per IV3-taakveld.
- **planix**: project- en maatregel-uitvoering onder doelen.
- **openregister**: opslag van programma's, doelen, indicatoren en templates.
- **openconnector**: sync met waarstaatjegemeente, CBS, en eigen meetbronnen.
- **opencatalogi**: open-data publicatie van indicatoren en metingen.

## Target users

Concerncontrollers, beleidsmedewerkers, programmamanagers, raadsleden (lezen), college van B&W, financieel adviseurs, provinciale toezichthouders, CBS en Forum Standaardisatie als afnemers van de aanleveringen.
