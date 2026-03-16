# MyDash — Overheidsfunctionaliteiten

> Functiepagina voor Nederlandse overheidsorganisaties.
> Gebruik deze checklist om te toetsen aan uw Programma van Eisen.

**Product:** MyDash
**Categorie:** Dashboard & informatievoorziening
**Licentie:** AGPL (vrije open source)
**Leverancier:** Conduction B.V.
**Platform:** Nextcloud (self-hosted / on-premise / cloud)

## Legenda

| Status | Betekenis |
|--------|-----------|
| Beschikbaar | Functionaliteit is beschikbaar in de huidige versie |
| Gepland | Functionaliteit staat op de roadmap |
| Via platform | Functionaliteit wordt geleverd door Nextcloud |
| Op aanvraag | Beschikbaar als maatwerk |
| N.v.t. | Niet van toepassing voor dit product |

---

## 1. Functionele eisen

### Dashboard-beheer

| # | Eis | Status | Toelichting |
|---|-----|--------|-------------|
| F-01 | Drag-and-drop grid layout | Beschikbaar | Vrij positioneren en schalen van widgets |
| F-02 | Meerdere dashboards per gebruiker | Beschikbaar | Onbeperkt dashboards aanmaken |
| F-03 | Dashboard wisselen | Beschikbaar | Snelle navigatie tussen dashboards |
| F-04 | Aangepaste tegels met iconen en kleuren | Beschikbaar | Snelkoppelingen naar veel-gebruikte tools |
| F-05 | Widget-styling (kleuren, randen, titels) | Beschikbaar | Per-widget aanpassing |

### Admin Templates & Beheer

| # | Eis | Status | Toelichting |
|---|-----|--------|-------------|
| F-06 | Admin-templates voor teams | Beschikbaar | Vooraf geconfigureerde dashboards uitrollen |
| F-07 | Distributie naar gebruikersgroepen | Beschikbaar | Eén-klik uitrol naar groepen |
| F-08 | Rechtenniveaus per template (bekijken / toevoegen / aanpassen) | Beschikbaar | Flexibele controle over gebruikersaanpassingen |
| F-09 | Verplichte widgets (niet verwijderbaar) | Beschikbaar | Beheerders pinnen belangrijke widgets |
| F-10 | Conditionele zichtbaarheid (groep, tijdstip, datum) | Beschikbaar | Slimme widget-weergave |

### Compatibiliteit

| # | Eis | Status | Toelichting |
|---|-----|--------|-------------|
| F-11 | Alle Nextcloud dashboard widgets | Beschikbaar | Werkt met elke bestaande widget |
| F-12 | Integratie met Nextcloud-apps | Beschikbaar | Widgets van Files, Calendar, Talk, etc. |

---

## 2. Technische eisen

| # | Eis | Status | Toelichting |
|---|-----|--------|-------------|
| T-01 | On-premise / self-hosted | Beschikbaar | Nextcloud-app |
| T-02 | Open source | Beschikbaar | AGPL, GitHub |
| T-03 | PHP 8.1+ | Beschikbaar | Moderne PHP |
| T-04 | Nextcloud 28-33 compatibel | Beschikbaar | Brede versie-ondersteuning |
| T-05 | Geen externe dependencies | Beschikbaar | Alleen Nextcloud vereist |

---

## 3. Beveiligingseisen

| # | Eis | Status | Toelichting |
|---|-----|--------|-------------|
| B-01 | Admin-only template beheer | Beschikbaar | Alleen beheerders maken templates |
| B-02 | Groepsgebaseerde toegang | Beschikbaar | Templates per gebruikersgroep |
| B-03 | BIO-compliance | Via platform | Nextcloud BIO |
| B-04 | 2FA | Via platform | Nextcloud 2FA |
| B-05 | SSO / SAML / LDAP | Via platform | Nextcloud SSO |

---

## 4. Privacyeisen (AVG/GDPR)

| # | Eis | Status | Toelichting |
|---|-----|--------|-------------|
| P-01 | Geen persoonsgegevens opslag | Beschikbaar | Dashboard-configuratie bevat geen PII |
| P-02 | Gebruikersconfiguratie alleen lokaal | Beschikbaar | Dashboard-instellingen per gebruiker |

---

## 5. Toegankelijkheidseisen

| # | Eis | Status | Toelichting |
|---|-----|--------|-------------|
| A-01 | WCAG 2.1 AA | Beschikbaar | Nextcloud-componenten |
| A-02 | EN 301 549 | Beschikbaar | Via WCAG AA |
| A-03 | Toetsenbordnavigatie | Beschikbaar | Drag-and-drop ook via toetsenbord |
| A-04 | Screenreader | Beschikbaar | ARIA-labels op widgets |
| A-05 | NL Design System | Beschikbaar | Via NL Design app |
| A-06 | Meertalig (NL/EN) | Beschikbaar | Volledige vertaling |

---

## 6. Beheer en onderhoud

| # | Eis | Status | Toelichting |
|---|-----|--------|-------------|
| BO-01 | Nextcloud App Store | Beschikbaar | Installatie via App Store |
| BO-02 | Automatische updates | Beschikbaar | Via Nextcloud app-updater |
| BO-03 | Admin settings pagina | Beschikbaar | Template-beheer |
| BO-04 | Documentatie | Beschikbaar | GitHub docs |
| BO-05 | Open source community | Beschikbaar | GitHub Issues + Discussions |
| BO-06 | Professionele ondersteuning (SLA) | Op aanvraag | Via Conduction B.V. |

---

## 7. Onderscheidende kenmerken

| Kenmerk | Toelichting |
|---------|-------------|
| **Admin templates** | Vooraf geconfigureerde dashboards uitrollen naar hele teams |
| **Verplichte widgets** | Belangrijke informatie die gebruikers niet kunnen verbergen |
| **Conditionele zichtbaarheid** | Widgets tonen op basis van groep, tijdstip of datum |
| **Drag-and-drop** | Intuïtieve positionering zonder technische kennis |
| **100% Nextcloud-compatibel** | Werkt met alle bestaande dashboard widgets |
| **Organisatie-breed** | Consistente informatievoorziening voor alle medewerkers |
