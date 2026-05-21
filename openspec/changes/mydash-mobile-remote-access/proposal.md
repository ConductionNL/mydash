---
status: proposed
---

# Proposal: Mobile and Remote Dashboard Access

## Executive Summary

MyDash currently requires office-based access over corporate networks with desktop-optimized interfaces. This proposal enables secure remote employee access from mobile devices and external networks, unlocking productivity for distributed teams while maintaining security posture.

## Features

### 1. Remote Work and Mobile Access
**Demand: 28** (3 tender mentions) | **Category:** Security | **Coverage:** MyDash

#### Capability
As a remote employee, I want secure access to mydash from any device or location, so that I can work productively outside the office.

#### Acceptance Criteria
1. GIVEN a remote employee with valid credentials, WHEN they log in from a mobile device outside the corporate network, THEN they can access their dashboard and all permitted features.
2. GIVEN a remote employee is authenticated, WHEN their session is idle beyond the configured timeout, THEN they are automatically signed out and must re-authenticate.
3. GIVEN a remote employee using a mobile browser, WHEN they navigate mydash, THEN the interface is fully usable with touch controls and adapts to the screen size without loss of functionality.

#### Impact
- Enables distributed workforce capabilities
- Reduces dependency on VPN for dashboard access
- Maintains security through session timeout and authentication enforcement

### 2. Access to Relevant Applications and News
**Demand: 25** (3 tender mentions) | **Category:** Security | **Coverage:** MyDash

#### Capability
As an employee, I want access to relevant applications and news in mydash, so that I can stay informed and productive from a single dashboard.

#### Acceptance Criteria
1. GIVEN an authenticated employee, WHEN they open mydash, THEN a curated list of relevant applications is displayed based on their role and permissions.
2. GIVEN an authenticated employee, WHEN they open mydash, THEN a news feed showing organisation-relevant news is visible without navigating away.
3. GIVEN a security administrator, WHEN they configure application and news access policies, THEN only authorised content is shown to each employee based on their assigned role.

#### Impact
- Centralizes access to critical applications and information
- Reduces context switching and improves productivity
- Enables role-based content filtering for security and compliance

## User Stories

### US-001: Remote Login from Mobile Device
**Derived from Feature 1 (Remote Work and Mobile Access)**

As a field employee, I want to log in to MyDash from my phone while at a client site, so that I can review my dashboard without returning to the office.

**Acceptance Criteria:**
1. GIVEN I have valid Nextcloud credentials
2. WHEN I visit the MyDash URL on my mobile device
3. THEN I can log in using Nextcloud's standard authentication
4. AND I see a mobile-optimized version of my dashboard
5. AND all dashboard features are accessible via touch

### US-002: Automatic Session Timeout
**Derived from Feature 1 (Remote Work and Mobile Access)**

As a security-conscious employee, I want my session to automatically expire after inactivity, so that my device is protected if I forget to log out.

**Acceptance Criteria:**
1. GIVEN I am logged into MyDash on a mobile device
2. WHEN I have not interacted with the dashboard for 15 minutes (configurable)
3. THEN my session is automatically terminated
4. AND the next dashboard access requires re-authentication

### US-003: View Role-Based Applications
**Derived from Feature 2 (Access to Relevant Applications and News)**

As a department manager, I want to see a list of applications relevant to my role, so that I can quickly access tools needed for my responsibilities.

**Acceptance Criteria:**
1. GIVEN I am authenticated in MyDash
2. WHEN I view the applications section
3. THEN I see only applications my role has permission to access
4. AND applications are grouped logically (by department or function)

### US-004: Configure Application Access Policies
**Derived from Feature 2 (Access to Relevant Applications and News)**

As a security administrator, I want to control which applications appear for each user role, so that sensitive tools are only accessible to authorized personnel.

**Acceptance Criteria:**
1. GIVEN I am an admin in MyDash
2. WHEN I access the admin settings for application access
3. THEN I can view all available applications and user roles
4. AND I can map which roles can see which applications
5. AND changes take effect immediately for all users

## Customer Journeys

### Journey: New Remote Hire Onboarding
**Trigger:** New employee joins company

1. **Authentication Phase** (Day 1)
   - Pain point: Employee is not yet at office, needs to access company systems immediately
   - Solution: Can log in from home using Nextcloud credentials
   
2. **Dashboard Discovery** (Day 1-2)
   - Pain point: Unsure which applications are relevant to their role
   - Solution: MyDash displays role-specific application list without manual configuration

3. **Remote Access** (Day 1+)
   - Pain point: Relies on VPN for secure access
   - Solution: MyDash provides secure direct access from any location with automatic session management

### Journey: Executive News Brief
**Trigger:** Executive starts their day

1. **Single-Point Access** (Morning)
   - Pain point: Scattered across multiple portals and news sources
   - Solution: MyDash dashboard displays curated organization news and relevant applications on one screen

2. **Mobile Browsing** (Travel)
   - Pain point: Need to stay informed while traveling
   - Solution: Mobile-optimized dashboard with news feed accessible from phone

3. **Role-Based Filtering** (Consistent)
   - Pain point: Sees irrelevant content from other departments
   - Solution: Admin-configured policies ensure only relevant content appears

## Stakeholders

### Information Security Officer
- **Responsibilities:** Define and enforce security policies for remote access, session timeout, and authentication
- **Goals:** Maintain security posture while enabling remote work; ensure audit trails for compliance
- **Success Criteria:** Zero security incidents from remote access; full session lifecycle logging

### Product Manager (MyDash)
- **Responsibilities:** Prioritize features; ensure mobile UX is competitive; manage releases
- **Goals:** Enable distributed workforce; increase daily active users; reduce support overhead
- **Success Criteria:** Mobile traffic increases to 30%+ of total; session timeout reduces support tickets by 25%

### System Administrator
- **Responsibilities:** Deploy MyDash; configure application and news access policies; manage user permissions
- **Goals:** Enable self-service application discovery; reduce onboarding time; maintain system stability
- **Success Criteria:** New users can access MyDash without manual admin intervention; policies are easy to manage

### Remote Employee
- **Responsibilities:** Use MyDash for daily work and information access
- **Goals:** Seamless access from any location; quick discovery of needed applications; stay informed
- **Success Criteria:** Can log in and access dashboard within 30 seconds; find relevant applications without searching

### Executive (Chief Officer)
- **Responsibilities:** Monitor organizational news and metrics from MyDash
- **Goals:** Single dashboard view of key information; mobile access while traveling; time savings
- **Success Criteria:** Spends less than 5 minutes per day on news/app discovery; uses dashboard on mobile 3+ days/week

## Deployment Scope

**In this spec:**
- Nextcloud authentication and session management for remote access
- Mobile-responsive UI for MyDash (touch-optimized)
- Role-based application visibility filtering
- News/content curation based on roles
- Session timeout configuration in admin settings

**Deferred to future spec (if needed):**
- Advanced analytics on app/news usage
- Personalized recommendation engine
- Deep single-sign-on integrations with external platforms

## Success Metrics

- Mobile users accessing dashboard: > 30% of total daily active users (target: 6 months)
- Session timeout reduction in support tickets: > 25% improvement in auth-related issues
- Admin configuration time for app/news policies: < 30 minutes per organization
- Mobile dashboard load time: < 2 seconds on 4G network
- User satisfaction with mobile experience: > 4.0/5.0 NPS score
