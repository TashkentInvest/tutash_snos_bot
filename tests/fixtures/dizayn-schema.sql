-- Dizayn-kod boti bazasi sxemasi (TutashDizayn_bot/bot/db.py dan) — faqat testlar uchun (sqlite).
CREATE TABLE IF NOT EXISTS users (
    user_id       INTEGER PRIMARY KEY,
    username      TEXT,
    first_name    TEXT,
    last_name     TEXT,
    language_code TEXT,
    first_seen    TEXT NOT NULL DEFAULT (datetime('now')),
    last_seen     TEXT NOT NULL DEFAULT (datetime('now')),
    start_count   INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS downloads (
    id        INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id   INTEGER NOT NULL REFERENCES users(user_id),
    district  TEXT NOT NULL,
    street    TEXT NOT NULL,
    file_name TEXT NOT NULL,
    sent_at   TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE TABLE IF NOT EXISTS file_cache (
    path    TEXT NOT NULL,
    size    INTEGER NOT NULL,
    mtime   INTEGER NOT NULL,
    file_id TEXT NOT NULL,
    PRIMARY KEY (path, size, mtime)
);
CREATE TABLE IF NOT EXISTS user_photos (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id        INTEGER NOT NULL REFERENCES users(user_id),
    file_id        TEXT NOT NULL,
    file_unique_id TEXT NOT NULL,
    path           TEXT NOT NULL,
    created_at     TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE TABLE IF NOT EXISTS districts (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT NOT NULL,
    sort_order INTEGER NOT NULL DEFAULT 0,
    is_active  BOOLEAN NOT NULL DEFAULT TRUE,
    phone      TEXT                         -- contact shown when the district is opened (Chamber of Commerce representative)
);
CREATE TABLE IF NOT EXISTS streets (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    district_id INTEGER NOT NULL REFERENCES districts(id),
    name        TEXT NOT NULL,
    sort_order  INTEGER NOT NULL DEFAULT 0,
    is_active   BOOLEAN NOT NULL DEFAULT TRUE
);
CREATE INDEX IF NOT EXISTS idx_streets_district ON streets(district_id);
CREATE TABLE IF NOT EXISTS street_files (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    street_id  INTEGER NOT NULL REFERENCES streets(id),
    file_name  TEXT NOT NULL,
    path       TEXT NOT NULL,          -- relative to DATA_DIR, forward slashes
    size       INTEGER NOT NULL DEFAULT 0,
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_files_street ON street_files(street_id);
CREATE TABLE IF NOT EXISTS kv (key TEXT PRIMARY KEY, value REAL NOT NULL);
-- Citizens' requests (objections, needs, suggestions). Answered from the dashboard, the bot delivers the answer.
CREATE TABLE IF NOT EXISTS feedback (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id        INTEGER NOT NULL REFERENCES users(user_id),
    level          TEXT NOT NULL,             -- problem level: low / medium / high
    text           TEXT NOT NULL,
    created_at     TEXT NOT NULL DEFAULT (datetime('now')),
    answer         TEXT,                      -- written in the dashboard
    answered_at    TEXT,
    answered_by    TEXT,                      -- admin e-mail
    answer_sent_at TEXT,                      -- set by the bot once the answer reached the user (or failed for good)
    answer_error   TEXT                       -- why it could not be delivered (user blocked the bot, ...)
);
CREATE INDEX IF NOT EXISTS idx_feedback_created ON feedback(created_at);
CREATE INDEX IF NOT EXISTS idx_feedback_user ON feedback(user_id);

-- District staff: check on the spot that businesses take down old signs and put up design-code ones.
CREATE TABLE IF NOT EXISTS staff (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    full_name        TEXT NOT NULL,
    phone            TEXT NOT NULL UNIQUE,      -- +998XXXXXXXXX; the person confirms it with the contact button
    district_id      INTEGER REFERENCES districts(id),  -- NULL: taken off every district (keeps bot link, evidence)
    streets_note     TEXT,                      -- the streets named when the person was assigned (free text)
    priority_streets TEXT,                      -- JSON array of street ids listed first in the Mini App
    user_id          INTEGER UNIQUE,            -- Telegram id, set once the phone was confirmed
    is_active        BOOLEAN NOT NULL DEFAULT TRUE,
    created_at       TEXT NOT NULL DEFAULT (datetime('now'))
);
-- A business on a street (an "object" of the design code), added by district staff in the Mini App. How many each street
-- should have comes from the official list (streets.planned_objects); the street's concept PDF stays whole.
CREATE TABLE IF NOT EXISTS objects (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    street_id   INTEGER NOT NULL REFERENCES streets(id),
    shop_name   TEXT NOT NULL,
    owner_name  TEXT,
    owner_phone TEXT,
    stir        TEXT,
    status      TEXT NOT NULL DEFAULT 'pending', -- pending / removed (old sign taken down) / installed (design code done)
    latitude    REAL,                           -- from the latest approved inspection; later ones must be near it
    longitude   REAL,
    is_active   BOOLEAN NOT NULL DEFAULT TRUE,  -- the admin's switch: 0 = added by mistake / a duplicate
    created_by  INTEGER REFERENCES staff(id),
    created_at  TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at  TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_objects_street ON objects(street_id);
CREATE INDEX IF NOT EXISTS idx_objects_status ON objects(status);
-- Evidence sent from the Mini App: location -> live photo -> live video -> comment. Changes the object only once approved.
CREATE TABLE IF NOT EXISTS inspections (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    object_id    INTEGER NOT NULL REFERENCES objects(id),
    staff_id     INTEGER NOT NULL REFERENCES staff(id),
    kind         TEXT NOT NULL,                 -- removed / installed
    step         TEXT NOT NULL DEFAULT 'photo', -- photo / location / point / video / comment / submitted
    latitude     REAL,                          -- where the staff member stood (the phone's location)
    longitude    REAL,
    accuracy     REAL,
    obj_latitude REAL,                          -- the object's place, picked on the map by the staff member
    obj_longitude REAL,
    distance_m   REAL,                          -- staff member <-> picked object place
    photo        TEXT,                          -- relative to UPLOADS_DIR
    photo_hash   TEXT,
    video        TEXT,
    video_hash   TEXT,
    comment      TEXT,
    review       TEXT,                          -- set on submit: pending / approved / rejected
    review_note  TEXT,
    reviewed_by  TEXT,
    reviewed_at  TEXT,
    created_at   TEXT NOT NULL DEFAULT (datetime('now')),
    submitted_at TEXT
);
CREATE INDEX IF NOT EXISTS idx_inspections_object ON inspections(object_id);
CREATE INDEX IF NOT EXISTS idx_inspections_review ON inspections(review);
CREATE INDEX IF NOT EXISTS idx_inspections_staff ON inspections(staff_id);
-- Messages the dashboard wants delivered (review results to staff); the bot sends them.
-- a staff member's working day in the Mini App (the attendance report): first and last request of the day (Tashkent date)
CREATE TABLE IF NOT EXISTS staff_days (
    staff_id INTEGER NOT NULL,
    day      TEXT NOT NULL,
    first_at TEXT NOT NULL,
    last_at  TEXT NOT NULL,
    hits     INTEGER NOT NULL DEFAULT 1,
    PRIMARY KEY (staff_id, day)
);
-- who gets the design-code report pictures the dashboard sends (people who started the bot)
CREATE TABLE IF NOT EXISTS report_recipients (
    user_id    INTEGER PRIMARY KEY,
    label      TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE TABLE IF NOT EXISTS outbox (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    text       TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    sent_at    TEXT,
    error      TEXT
);
-- A post from the dashboard ("Post yuborish"): a picture or video and a text, to the people the admin ticked. The bot
-- sends it (post_worker) and keeps every message id, so the post can be taken back from the chats within 48 hours.
CREATE TABLE IF NOT EXISTS posts (
    id                 INTEGER PRIMARY KEY AUTOINCREMENT,
    caption            TEXT,
    media_type         TEXT,                      -- photo | video | NULL (text only)
    media_path         TEXT,                      -- under UPLOADS_DIR
    media_width        INTEGER,                   -- the video's frame (turned upright), so Telegram does not stretch it
    media_height       INTEGER,
    media_duration     INTEGER,
    file_id            TEXT,                      -- Telegram's id of the uploaded file: uploaded once, then reused
    audience           TEXT,                      -- JSON: the groups and districts the admin chose
    ack_button         BOOLEAN NOT NULL DEFAULT TRUE,
    status             TEXT NOT NULL DEFAULT 'sending',  -- sending | sent
    total              INTEGER NOT NULL DEFAULT 0,
    delivered          INTEGER NOT NULL DEFAULT 0,
    failed             INTEGER NOT NULL DEFAULT 0,
    created_by         INTEGER,                   -- the dashboard user
    created_at         TEXT NOT NULL DEFAULT (datetime('now')),
    finished_at        TEXT,
    recalled_at        TEXT,                      -- "Botdan o'chirish" asked
    recalled           INTEGER NOT NULL DEFAULT 0,
    recall_failed      INTEGER NOT NULL DEFAULT 0,
    recall_finished_at TEXT
);
CREATE TABLE IF NOT EXISTS post_deliveries (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    post_id      INTEGER NOT NULL REFERENCES posts(id) ON DELETE CASCADE,
    user_id      INTEGER NOT NULL,                -- Telegram id (users.user_id)
    status       TEXT NOT NULL DEFAULT 'pending', -- pending | sent | failed
    message_id   INTEGER,
    sent_at      TEXT,
    ack_at       TEXT,                            -- pressed "Tanishdim"
    recalled_at  TEXT,
    error        TEXT,
    recall_error TEXT,
    UNIQUE (post_id, user_id)
);
ALTER TABLE users ADD COLUMN full_name TEXT;
ALTER TABLE users ADD COLUMN phone TEXT;
ALTER TABLE users ADD COLUMN latitude REAL;
ALTER TABLE users ADD COLUMN longitude REAL;
ALTER TABLE users ADD COLUMN registered_at TEXT;
ALTER TABLE users ADD COLUMN consent_at TEXT;
ALTER TABLE users ADD COLUMN lang TEXT;
ALTER TABLE users ADD COLUMN concept_at TEXT;
ALTER TABLE users ADD COLUMN approved_at TEXT;
ALTER TABLE users ADD COLUMN fb_wait TEXT;
ALTER TABLE users ADD COLUMN fb_wait_until REAL;
ALTER TABLE users ADD COLUMN chat_id INTEGER;
ALTER TABLE users ADD COLUMN blocked_at TEXT;
ALTER TABLE streets ADD COLUMN planned_objects INTEGER NOT NULL DEFAULT 0;
ALTER TABLE streets ADD COLUMN start_lat REAL;
ALTER TABLE streets ADD COLUMN start_lon REAL;
ALTER TABLE streets ADD COLUMN end_lat REAL;
ALTER TABLE streets ADD COLUMN end_lon REAL;
ALTER TABLE streets ADD COLUMN path TEXT;
ALTER TABLE streets ADD COLUMN length_km REAL;
ALTER TABLE streets ADD COLUMN nonres_objects INTEGER;
ALTER TABLE staff ADD COLUMN invite_token TEXT;
ALTER TABLE staff ADD COLUMN invite_expires TEXT;
ALTER TABLE staff ADD COLUMN phone2 TEXT;
ALTER TABLE outbox ADD COLUMN photo TEXT;
ALTER TABLE report_recipients ADD COLUMN daily_report BOOLEAN NOT NULL DEFAULT TRUE;
ALTER TABLE report_recipients ADD COLUMN attendance_report BOOLEAN NOT NULL DEFAULT TRUE;
ALTER TABLE report_recipients ADD COLUMN hourly_report BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE objects ADD COLUMN address TEXT;
ALTER TABLE objects ADD COLUMN found_lat REAL;
ALTER TABLE objects ADD COLUMN found_lon REAL;
