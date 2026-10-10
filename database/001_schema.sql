
CREATE DATABASE IF NOT EXISTS transcript_system
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE transcript_system;

-- -------------------------------------------------------------
-- 1. departments
-- -------------------------------------------------------------
CREATE TABLE departments (
    id       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code     VARCHAR(10)  NOT NULL,
    name     VARCHAR(120) NOT NULL,
    faculty  VARCHAR(120) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_departments_code (code)
) ENGINE=InnoDB;

-- -------------------------------------------------------------
-- 2. academic_sessions  (e.g. 2025/2026)
-- -------------------------------------------------------------
CREATE TABLE academic_sessions (
    id    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name  VARCHAR(9)   NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sessions_name (name),
    CONSTRAINT chk_session_format CHECK (name REGEXP '^[0-9]{4}/[0-9]{4}$')
) ENGINE=InnoDB;

-- -------------------------------------------------------------
-- 3. semesters  (First Semester / Second Semester)
-- -------------------------------------------------------------
CREATE TABLE semesters (
    id          INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    name        VARCHAR(20)      NOT NULL,
    sort_order  TINYINT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_semesters_name (name),
    UNIQUE KEY uq_semesters_sort (sort_order)
) ENGINE=InnoDB;

-- -------------------------------------------------------------
-- 4. grades  (grade scale)
-- -------------------------------------------------------------
CREATE TABLE grades (
    grade        CHAR(1)      NOT NULL,
    grade_point  DECIMAL(2,1) NOT NULL,
    min_score    DECIMAL(5,2) NOT NULL,
    max_score    DECIMAL(5,2) NOT NULL,
    PRIMARY KEY (grade),
    CONSTRAINT chk_grade_range CHECK (min_score <= max_score)
) ENGINE=InnoDB;

-- -------------------------------------------------------------
-- 5. admins  (separate from students; no roles)
-- -------------------------------------------------------------
CREATE TABLE admins (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username       VARCHAR(50)  NOT NULL,
    full_name      VARCHAR(150) NOT NULL,
    email          VARCHAR(120) NULL,
    password_hash  VARCHAR(255) NOT NULL,
    is_active      TINYINT(1)   NOT NULL DEFAULT 1,
    last_login_at  DATETIME     NULL,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_admins_username (username),
    UNIQUE KEY uq_admins_email (email)
) ENGINE=InnoDB;

-- -------------------------------------------------------------
-- 6. students
-- -------------------------------------------------------------
CREATE TABLE students (
    id                INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    matric_no         VARCHAR(20)       NOT NULL,
    full_name         VARCHAR(150)      NOT NULL,
    email             VARCHAR(120)      NOT NULL,
    phone             VARCHAR(20)       NULL,
    date_of_birth     DATE              NULL,
    department_id     INT UNSIGNED      NOT NULL,
    level             SMALLINT UNSIGNED NOT NULL,
    entry_session_id  INT UNSIGNED      NULL,
    password_hash     VARCHAR(255)      NOT NULL,
    status            ENUM('active','suspended','graduated') NOT NULL DEFAULT 'active',
    last_login_at     DATETIME          NULL,
    created_at        DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_students_matric (matric_no),
    UNIQUE KEY uq_students_email (email),
    KEY idx_students_department (department_id),
    CONSTRAINT chk_students_level CHECK (level BETWEEN 100 AND 600),
    CONSTRAINT fk_students_department
        FOREIGN KEY (department_id) REFERENCES departments (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_students_entry_session
        FOREIGN KEY (entry_session_id) REFERENCES academic_sessions (id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

-- -------------------------------------------------------------
-- 7. courses
-- -------------------------------------------------------------
CREATE TABLE courses (
    id             INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    code           VARCHAR(10)      NOT NULL,   -- e.g. 'CSC 204' (with a space)
    title          VARCHAR(150)     NOT NULL,
    unit           TINYINT UNSIGNED NOT NULL,
    department_id  INT UNSIGNED     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_courses_code (code),
    KEY idx_courses_department (department_id),
    CONSTRAINT chk_courses_unit CHECK (unit BETWEEN 1 AND 10),
    CONSTRAINT fk_courses_department
        FOREIGN KEY (department_id) REFERENCES departments (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

-- -------------------------------------------------------------
-- 8. results  (student x course x session x semester)
-- -------------------------------------------------------------
CREATE TABLE results (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    student_id   INT UNSIGNED NOT NULL,
    course_id    INT UNSIGNED NOT NULL,
    session_id   INT UNSIGNED NOT NULL,
    semester_id  INT UNSIGNED NOT NULL,
    score        DECIMAL(5,2) NULL,
    grade        CHAR(1)      NOT NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_result (student_id, course_id, session_id, semester_id),
    KEY idx_results_lookup (student_id, session_id, semester_id),
    KEY idx_results_course (course_id),
    CONSTRAINT chk_results_score CHECK (score IS NULL OR score BETWEEN 0 AND 100),
    CONSTRAINT fk_results_student
        FOREIGN KEY (student_id)  REFERENCES students (id)          ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_results_course
        FOREIGN KEY (course_id)   REFERENCES courses (id)           ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_results_session
        FOREIGN KEY (session_id)  REFERENCES academic_sessions (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_results_semester
        FOREIGN KEY (semester_id) REFERENCES semesters (id)         ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_results_grade
        FOREIGN KEY (grade)       REFERENCES grades (grade)         ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

-- -------------------------------------------------------------
-- 9. transcript_requests  (request + issued transcript record)
--    Issued-transcript columns stay NULL until status = 'ready'.
-- -------------------------------------------------------------
CREATE TABLE transcript_requests (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    request_no          VARCHAR(20)  NOT NULL,                -- e.g. TR-2026-000123
    student_id          INT UNSIGNED NOT NULL,
    status              ENUM('pending_payment','paid','processing','ready','rejected')
                        NOT NULL DEFAULT 'pending_payment',
    scope               ENUM('full','session') NOT NULL DEFAULT 'full',
    session_id          INT UNSIGNED NULL,                    -- required when scope = 'session'
    fee_amount          DECIMAL(10,2) NOT NULL,               -- copied from config at request time
    purpose             VARCHAR(255) NULL,
    rejection_reason    VARCHAR(255) NULL,
    -- Generated: holds student_id while the request is open, NULL once closed.
    -- A UNIQUE index on it means one open request per student.
    open_student_id     INT UNSIGNED
        GENERATED ALWAYS AS (
            IF(status IN ('pending_payment','paid','processing'), student_id, NULL)
        ) VIRTUAL,
    ready_at            DATETIME     NULL,
    transcript_no       VARCHAR(25)  NULL,
    total_units         SMALLINT UNSIGNED NULL,
    gpa                 DECIMAL(3,2) NULL,                    -- only for scope = 'session'
    cgpa                DECIMAL(3,2) NULL,
    download_count      INT UNSIGNED NOT NULL DEFAULT 0,
    last_downloaded_at  DATETIME     NULL,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_request_no (request_no),
    UNIQUE KEY uq_transcript_no (transcript_no),
    UNIQUE KEY uq_one_open_request (open_student_id),
    KEY idx_req_student (student_id, status),
    KEY idx_req_status (status, created_at),
    CONSTRAINT chk_req_fee CHECK (fee_amount > 0),
    CONSTRAINT chk_req_scope CHECK (
        (scope = 'full'    AND session_id IS NULL) OR
        (scope = 'session' AND session_id IS NOT NULL)
    ),
    CONSTRAINT chk_req_ready CHECK (
        status <> 'ready' OR (
            transcript_no IS NOT NULL AND total_units IS NOT NULL
            AND cgpa IS NOT NULL AND ready_at IS NOT NULL
        )
    ),
    -- ON UPDATE RESTRICT (not CASCADE): MySQL/MariaDB refuse CHECK and generated columns
    -- on a column whose foreign key has a cascading action. Ids never change anyway.
    CONSTRAINT fk_req_student
        FOREIGN KEY (student_id) REFERENCES students (id)          ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_req_session
        FOREIGN KEY (session_id) REFERENCES academic_sessions (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB;

-- -------------------------------------------------------------
-- 10. payments  (attempts against a request)
-- -------------------------------------------------------------
CREATE TABLE payments (
    id               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    request_id       INT UNSIGNED  NOT NULL,
    reference        VARCHAR(50)   NOT NULL,
    gateway          ENUM('paystack') NOT NULL DEFAULT 'paystack',
    gateway_txn_id   VARCHAR(100)  NULL,
    amount           DECIMAL(10,2) NOT NULL,                  -- naira; backend checks = fee_amount
    currency         CHAR(3)       NOT NULL DEFAULT 'NGN',
    status           ENUM('initiated','success','failed','cancelled') NOT NULL DEFAULT 'initiated',
    failure_reason   VARCHAR(255)  NULL,
    raw_response     JSON          NULL,
    initiated_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    paid_at          DATETIME      NULL,
    verified_at      DATETIME      NULL,
    -- Generated: 1 when successful, NULL otherwise. UNIQUE (request_id, success_flag)
    -- allows many failed attempts but only one success per request.
    success_flag     TINYINT
        GENERATED ALWAYS AS (IF(status = 'success', 1, NULL)) VIRTUAL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_payment_ref (reference),
    UNIQUE KEY uq_one_success (request_id, success_flag),
    KEY idx_pay_request (request_id),
    KEY idx_pay_status (status, paid_at),
    CONSTRAINT chk_pay_amount CHECK (amount > 0),
    CONSTRAINT fk_pay_request
        FOREIGN KEY (request_id) REFERENCES transcript_requests (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

-- =============================================================
-- Views (derived data; GPA = SUM(unit x grade_point) / SUM(unit))
-- =============================================================

CREATE VIEW v_result_details AS
SELECT r.id, r.student_id, s.matric_no,
       ses.name AS session, sem.name AS semester, sem.sort_order,
       c.code, c.title, c.unit, r.score, r.grade, g.grade_point,
       c.unit * g.grade_point AS quality_points
FROM results r
JOIN students s            ON s.id   = r.student_id
JOIN courses c             ON c.id   = r.course_id
JOIN academic_sessions ses ON ses.id = r.session_id
JOIN semesters sem         ON sem.id = r.semester_id
JOIN grades g              ON g.grade = r.grade;

CREATE VIEW v_semester_gpa AS
SELECT student_id, session, semester, sort_order,
       SUM(unit)           AS total_units,
       SUM(quality_points) AS total_points,
       ROUND(SUM(quality_points) / SUM(unit), 2) AS gpa
FROM v_result_details
GROUP BY student_id, session, semester, sort_order;

CREATE VIEW v_request_latest_payment AS
SELECT tr.id AS request_id, tr.request_no, tr.student_id, tr.status AS request_status,
       tr.fee_amount, p.reference, p.status AS payment_status, p.paid_at, p.verified_at
FROM transcript_requests tr
LEFT JOIN payments p
       ON p.id = (SELECT MAX(p2.id) FROM payments p2 WHERE p2.request_id = tr.id);

-- Retaken courses: only the latest attempt counts toward CGPA.
CREATE VIEW v_counted_results AS
SELECT d.*
FROM v_result_details d
WHERE NOT EXISTS (
    SELECT 1 FROM v_result_details d2
    WHERE d2.student_id = d.student_id
      AND d2.code = d.code
      AND (d2.session > d.session
           OR (d2.session = d.session AND d2.sort_order > d.sort_order))
);