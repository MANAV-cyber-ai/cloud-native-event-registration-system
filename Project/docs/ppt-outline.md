# Capstone PPT (Proper 6-Slide Version)

Use this exact structure for your final presentation.

## Slide 1: Project Overview and Objective
Title: **Cloud Event Registration Portal**

Include:
- University problem: manual/fragmented event registration
- Objective: build a simple but scalable online registration system
- Scope: Tech Fest, Cultural Events, Workshops, Seminars
- Expected output: public portal + cloud-ready architecture

Speaker line:
"We built a working local prototype that follows cloud-native design principles and can be migrated to GCP services with minimal changes."

## Slide 2: Functional Flow (Use Case Demo)
Add a flow diagram:
1. Student opens public URL
2. Form auto-loads events and departments
3. Student submits registration
4. Backend validates and stores data
5. Student receives confirmation/waitlist response
6. Admin monitors registrations in dashboard

Mention implemented validations:
- required fields
- valid email/phone
- duplicate prevention
- deadline and seat checks

## Slide 3: Architecture Diagram (Current vs Target)
Use two blocks on same slide.

### Local Prototype (Implemented)
Browser -> Apache/PHP -> MySQL (`cloud_event_registration_db`)

### Proposed Cloud Architecture (GCP)
Browser -> Cloud Run API -> Cloud SQL
Cloud Run -> Pub/Sub (future notifications/analytics)
Cloud Monitoring + Logging for reliability

Speaker line:
"Current system is monolithic local deployment, but data model and API design are already migration-friendly."

## Slide 4: Google Cloud Services Used/Planned + Justification
- **Cloud Run**: serverless container hosting, autoscaling, no server management
- **Cloud SQL (MySQL)**: managed relational DB, backups, HA options
- **Cloud Storage + CDN**: fast static content delivery (optional split frontend)
- **Pub/Sub**: loose coupling for notification service and analytics pipeline
- **Secret Manager**: secure DB credential handling
- **Cloud Monitoring/Logging**: operational visibility and alerts

## Slide 5: Database and Engineering Decisions
Show ER summary (master + transactional entities):
- Master: event_categories, departments, venues, coordinators
- Core: events, students, registrations
- Ops: activity_log, notification_outbox

Highlight why this is “proper”:
- normalized design
- foreign keys and unique constraints
- reporting views (`v_registration_overview`, `v_event_capacity`)
- extensible for future integrations

## Slide 6: Learnings, Challenges, and Next Steps
### Key Learnings
- API-first design helps cloud migration
- normalization improves data quality
- capacity/waitlist logic reflects real-world workflows

### Challenges
- duplicate registrations
- concurrency around seat availability
- balancing simplicity with extensibility

### Next Steps
- deploy API to Cloud Run
- migrate DB to Cloud SQL
- consume `notification_outbox` using background worker + email service
- add secure admin auth

---

## Optional Extra Slide (if asked)
Title: **Live Demo Walkthrough**
- Open form
- Submit registration
- Show DB row in phpMyAdmin
- Show admin dashboard update
