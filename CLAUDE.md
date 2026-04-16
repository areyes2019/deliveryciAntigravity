## CONTEXT LOADING — PROJECT ONBOARDING

You are now working inside an active production codebase. Before suggesting any change, improvement, or fix, you MUST fully understand the project. Follow this protocol:

---

### STEP 1 — READ THE FILE TREE FIRST

Run the following and internalize the full structure:
```
find . -type f \( -name "*.php" -o -name "*.vue" -o -name "*.js" -o -name "*.ts" -o -name "*.json" \) \
  | grep -v node_modules | grep -v vendor | grep -v .git | head -120
```

Identify:
- All CI4 Controllers, Models, and Routes
- All Vue3 components, composables, stores (Pinia/Vuex)
- Config files: .env, database.php, Routes.php, app.js / main.js
- Any WebSocket or GPS/tracking service files

---

### STEP 2 — UNDERSTAND THE ARCHITECTURE

After reading the tree, map the system in your mind:

**Backend (CodeIgniter 4):**
- What routes exist? (app/Config/Routes.php)
- What are the main entities? (Packages, Drivers, Locations, Orders, Users…)
- How is GPS data received and stored? (REST endpoint? WebSocket? Cron?)
- What is the DB schema for tracking? (migrations or model $allowedFields)
- Is there an API layer (REST/JSON responses) for the Vue frontend?
- Auth system: CI4 Shield? Custom? Sessions? JWT?

**Frontend (Vue 3):**
- Is it Vite or Webpack?
- State management: Pinia or Vuex?
- Routing: Vue Router? What are the main views?
- Map library: Leaflet, Google Maps, Mapbox?
- How does live tracking work? (WebSocket, polling, SSE?)
- How does it consume the CI4 API? (Axios? Fetch? Composables?)

**GPS / Live Tracking:**
- What is the data flow? Device → [how?] → CI4 → [how?] → Vue
- Is there a real-time layer? (Socket.io, Ratchet, Pusher, native SSE?)
- How often is location updated?
- What is the package status lifecycle? (pending → in_transit → delivered…)

---

### STEP 3 — READ THE KEY FILES

Read these files completely before doing anything else:
1. `app/Config/Routes.php`
2. `app/Config/Database.php` or `.env`
3. Main tracking Controller (search for GPS, location, tracking in filename)
4. Main Package/Shipment Model
5. `src/main.js` or `resources/js/app.js`
6. Main Pinia store or Vuex store related to tracking
7. The Vue component that renders the live map
8. Any WebSocket or real-time service file

---

### STEP 4 — BUILD YOUR MENTAL MODEL

After reading, confirm you understand:
- [ ] The full lifecycle of a package from creation to delivery
- [ ] How a driver's GPS coordinates reach the database
- [ ] How the frontend receives and renders live position updates
- [ ] The user roles (admin, driver, client?) and what each can do
- [ ] Any external integrations (SMS, email, payment, map APIs)
- [ ] Current tech debt or TODOs visible in comments

---

### STEP 5 — CONFIRM BEFORE ACTING

Once you have read everything, output a structured summary:

```
PROJECT SUMMARY
===============
Stack: CI4 [version] + Vue 3 + [Vite/Webpack] + [Pinia/Vuex]
DB: [MySQL/PostgreSQL] — main tables: [list]
Auth: [method]
Real-time: [WebSocket lib / polling / SSE]
Map: [library]
Package lifecycle: [status1 → status2 → …]
Key entities: [list]
Open questions: [anything unclear that you need to ask]
```

Only after this summary, ask the user what they want to improve or fix.

---

### WORKING RULES (always active)

- Never modify a file without showing the diff first
- Never assume a field exists in the DB — check the migration or model
- Never replace a working pattern with a new one without asking
- When touching GPS/real-time code, always consider race conditions and connection drops
- Prefer CI4 conventions (Services, Entities, BaseModel) over raw queries
- Prefer Vue 3 Composition API + `
- Respuestas siempre en epañol