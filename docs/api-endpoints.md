# SDA Church of Palkan – API Specification (Initial Draft)

Base URL
- /api
 - JSON over HTTP
 - Auth via session cookie after login or Bearer token (future)

Authentication
- POST /api/auth/login
 - Body: { "username": "string", "password": "string" }
 - 200: { "user": { "id": n, "username": "string", "role": "string" } }
 - 401: { "error": "invalid_credentials" }
- POST /api/auth/logout
 - 204
- GET /api/auth/me
 - 200: { "id": n, "username": "string", "role": "string", "member_id": n|null }

Members
- GET /api/members
 - Query: q, status, page, pageSize
 - 200: { "items": [], "total": n }
- GET /api/members/{id}
 - 200: { "id": n, "first_name": "string", "last_name": "string", ... }
- POST /api/members
 - Body: minimal profile fields
 - 201: { "id": n }
- PUT /api/members/{id}
 - Body: updatable fields
 - 200: { "id": n }
- DELETE /api/members/{id}
 - 204
- POST /api/members/{id}/photo
 - multipart/form-data: photo
 - 200: { "photo_uri": "string" }
- POST /api/members/{id}/face/enroll
 - Body: { "image_base64": "string", "consent": true }
 - 201: { "status": "enrolled", "version": n }
- DELETE /api/members/{id}/face
 - 204

Households and Ministries
- GET /api/households, POST /api/households, GET/PUT/DELETE /api/households/{id}
- POST /api/households/{id}/members
- GET /api/ministries, POST /api/ministries, GET/PUT/DELETE /api/ministries/{id}
- POST /api/ministries/{id}/members

Attendance
- GET /api/attendance/events
 - Query: date
- POST /api/attendance/events
 - Body: { "name": "string", "type": "worship|sabbath_school|meeting|other", "date": "YYYY-MM-DD", "start_time": "HH:MM", "end_time": "HH:MM" }
- GET /api/attendance/events/{id}
- PUT /api/attendance/events/{id}
- DELETE /api/attendance/events/{id}
- POST /api/attendance/events/{id}/checkin/face
 - Body: { "image_base64": "string" }
 - 200: { "member_id": n, "confidence": 0.0, "status": "present" }
- POST /api/attendance/events/{id}/checkin/manual
 - Body: { "member_id": n, "status": "present|late|excused" }
 - 201: { "id": n }
- GET /api/attendance/events/{id}/logs
 - Query: page, pageSize
 - 200: { "items": [], "total": n, "page": n, "pageSize": n }
- GET /api/attendance/reports/summary
 - Query: from, to, type

Finance
- GET /api/funds
- POST /api/funds
- GET /api/funds/{id}
- PUT /api/funds/{id}
- DELETE /api/funds/{id}
- GET /api/contributions
 - Query: member_id, fund_id, from, to, page, pageSize
- POST /api/contributions
 - Body: { "member_id": n|null, "fund_id": n, "amount": 0.0, "date": "YYYY-MM-DD", "reference_no": "string" }
- GET /api/contributions/{id}
- DELETE /api/contributions/{id}
- GET /api/reports/finance/period
 - Query: from, to

Lessons Library
- GET /api/lessons
 - Query: category, week_no, date, page, pageSize
- POST /api/lessons
- GET /api/lessons/{id}
- PUT /api/lessons/{id}
- DELETE /api/lessons/{id}
- POST /api/lessons/{id}/file
 - multipart/form-data: file
 - 200: { "id": n, "file_uri": "string" }

Events and Announcements
- GET /api/events
- POST /api/events
- GET /api/events/{id}
- PUT /api/events/{id}
- DELETE /api/events/{id}
- GET /api/announcements
- POST /api/announcements
- GET /api/announcements/{id}
- PUT /api/announcements/{id}
- DELETE /api/announcements/{id}

Admin
- GET /api/users
- POST /api/users
- GET /api/users/{id}
- PUT /api/users/{id}
- DELETE /api/users/{id}
- GET /api/roles
- POST /api/roles
- GET /api/permissions
- POST /api/roles/{id}/permissions

Face Service Contract
- POST /face/enroll
 - Body: { "member_id": n, "image_base64": "string" }
 - 201: { "embedding": "base64", "version": n }
- POST /face/match
 - Body: { "image_base64": "string" }
 - 200: { "member_id": n|null, "confidence": 0.0 }

Error Model
- 4xx/5xx: { "error": "code", "message": "string" }

Pagination
- Query: page, pageSize
- Response wrapper: { "items": [], "total": n, "page": n, "pageSize": n }

Sorting and Filtering
- Common fields: sort, order
- Filter by date ranges where applicable

Security
- Role-based authorization per endpoint
- Sensitive actions audited
