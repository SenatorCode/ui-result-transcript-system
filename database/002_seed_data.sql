-- =============================================================
-- 002_seed_data.sql   (FAKE DATA ONLY)
-- Run after 001_create_tables.sql.  Safe to run once on a fresh database.
--
-- Test logins
--   Students: matric number (e.g. CSC/2023/001)  password: Test@1234
--   Admins:   username registry.admin / finance.admin  password: Admin@1234
-- Hashes below were made with PHP password_hash() (bcrypt), so password_verify() works.
--
-- Test cases covered
--   CSC/2023/001  full history; CSC 204 failed in 2024/2025 and retaken in 2025/2026
--                 Second Semester (retake counts toward CGPA, both attempts show on transcript);
--                 matches the brief's example (CSC 204, grade A, Second Semester)
--   CSC/2023/002  NO results for 2025/2026 Second Semester (empty case)
--   CSC/2025/002  no results at all
--   CSC/2024/003  suspended account (cannot log in)
--   Requests: ready (full), ready (session), paid, processing, pending_payment, rejected
--   Payments: success, failed, initiated
-- =============================================================

USE transcript_system;

-- departments
INSERT INTO departments (id, code, name, faculty) VALUES
(1, 'CSC', 'Computer Science', 'Faculty of Computing'),
(2, 'MTH', 'Mathematics', 'Faculty of Science'),
(3, 'STA', 'Statistics', 'Faculty of Science'),
(4, 'GST', 'General Studies', 'Directorate of General Studies');

-- academic sessions
INSERT INTO academic_sessions (id, name) VALUES
(1, '2022/2023'),
(2, '2023/2024'),
(3, '2024/2025'),
(4, '2025/2026');

-- semesters
INSERT INTO semesters (id, name, sort_order) VALUES
(1, 'First Semester', 1),
(2, 'Second Semester', 2);

-- grade scale (confirm with the department)
INSERT INTO grades (grade, grade_point, min_score, max_score) VALUES
('A', 5.0, 70, 100),
('B', 4.0, 60, 69.99),
('C', 3.0, 50, 59.99),
('D', 2.0, 45, 49.99),
('E', 1.0, 40, 44.99),
('F', 0.0, 0, 39.99);

-- courses
INSERT INTO courses (id, code, title, unit, department_id) VALUES
(1, 'CSC 101', 'Introduction to Computer Science', 3, 1),
(2, 'CSC 102', 'Introduction to Programming', 3, 1),
(3, 'MTH 101', 'General Mathematics I', 3, 2),
(4, 'MTH 102', 'General Mathematics II', 3, 2),
(5, 'STA 111', 'Descriptive Statistics', 3, 3),
(6, 'GST 111', 'Use of English I', 2, 4),
(7, 'GST 112', 'Logic and Philosophy', 2, 4),
(8, 'CSC 201', 'Data Structures', 3, 1),
(9, 'CSC 202', 'Computer Organisation', 3, 1),
(10, 'CSC 203', 'Discrete Structures', 3, 1),
(11, 'MTH 201', 'Linear Algebra', 3, 2),
(12, 'CSC 204', 'Object-Oriented Programming', 3, 1),
(13, 'CSC 206', 'Operating Systems', 3, 1),
(14, 'STA 202', 'Probability Theory', 3, 3),
(15, 'CSC 301', 'Design and Analysis of Algorithms', 3, 1),
(16, 'CSC 302', 'Database Systems', 3, 1),
(17, 'CSC 303', 'Software Engineering', 3, 1),
(18, 'CSC 304', 'Computer Networks', 3, 1);

-- admins (password: Admin@1234)
INSERT INTO admins (id, username, full_name, email, password_hash, is_active) VALUES
(1, 'registry.admin', 'Registry Officer', 'registry@example.com', '$2y$10$J4QBRKvdimpXfnhf5QV9Uurjm9nMIaNJ1QajIarpFlo3uADei97nG', 1),
(2, 'finance.admin', 'Finance Officer', 'finance@example.com', '$2y$10$J4QBRKvdimpXfnhf5QV9Uurjm9nMIaNJ1QajIarpFlo3uADei97nG', 1);

-- students (password: Test@1234)
INSERT INTO students (id, matric_no, full_name, email, phone, date_of_birth, department_id, level, entry_session_id, password_hash, status) VALUES
(1, 'CSC/2023/001', 'Adewale Samuel Ogunleye', 'adewale.ogunleye@example.com', '08011110001', '2003-03-14', 1, 300, 2, '$2y$10$oU3c6KxgKk84BoMlOCExHOsmwHMH7SQjrKzMK97IogKKH5wJ/kTJ6', 'active'),
(2, 'CSC/2023/002', 'Fatima Aisha Bello', 'fatima.bello@example.com', '08011110002', '2003-07-02', 1, 300, 2, '$2y$10$oU3c6KxgKk84BoMlOCExHOsmwHMH7SQjrKzMK97IogKKH5wJ/kTJ6', 'active'),
(3, 'CSC/2023/003', 'Chinedu Daniel Okafor', 'chinedu.okafor@example.com', NULL, '2002-11-21', 1, 300, 2, '$2y$10$oU3c6KxgKk84BoMlOCExHOsmwHMH7SQjrKzMK97IogKKH5wJ/kTJ6', 'active'),
(4, 'CSC/2024/001', 'Tolulope Grace Adeyemi', 'tolu.adeyemi@example.com', '08011110004', '2004-01-30', 1, 200, 3, '$2y$10$oU3c6KxgKk84BoMlOCExHOsmwHMH7SQjrKzMK97IogKKH5wJ/kTJ6', 'active'),
(5, 'CSC/2024/002', 'Musa Yusuf Ibrahim', 'musa.ibrahim@example.com', NULL, '2004-05-18', 1, 200, 3, '$2y$10$oU3c6KxgKk84BoMlOCExHOsmwHMH7SQjrKzMK97IogKKH5wJ/kTJ6', 'active'),
(6, 'CSC/2025/001', 'Ngozi Blessing Eze', 'ngozi.eze@example.com', '08011110006', '2006-09-09', 1, 100, 4, '$2y$10$oU3c6KxgKk84BoMlOCExHOsmwHMH7SQjrKzMK97IogKKH5wJ/kTJ6', 'active'),
(7, 'CSC/2025/002', 'Kehinde Michael Oladipo', 'kehinde.oladipo@example.com', NULL, '2006-12-25', 1, 100, 4, '$2y$10$oU3c6KxgKk84BoMlOCExHOsmwHMH7SQjrKzMK97IogKKH5wJ/kTJ6', 'active'),
(8, 'CSC/2024/003', 'Emeka Victor Nwosu', 'emeka.nwosu@example.com', '08011110008', '2004-04-04', 1, 200, 3, '$2y$10$oU3c6KxgKk84BoMlOCExHOsmwHMH7SQjrKzMK97IogKKH5wJ/kTJ6', 'suspended');

-- results (grade comes from the scale: A>=70, B>=60, C>=50, D>=45, E>=40, F<40)
INSERT INTO results (student_id, course_id, session_id, semester_id, score, grade) VALUES
(1, 1, 2, 1, 78, 'A'),
(1, 3, 2, 1, 65, 'B'),
(1, 5, 2, 1, 72, 'A'),
(1, 6, 2, 1, 81, 'A'),
(1, 2, 2, 2, 74, 'A'),
(1, 4, 2, 2, 58, 'C'),
(1, 7, 2, 2, 69, 'B'),
(1, 8, 3, 1, 71, 'A'),
(1, 10, 3, 1, 66, 'B'),
(1, 11, 3, 1, 62, 'B'),
(1, 9, 3, 2, 68, 'B'),
(1, 12, 3, 2, 35, 'F'),
(1, 13, 3, 2, 73, 'A'),
(1, 14, 3, 2, 55, 'C'),
(1, 15, 4, 1, 76, 'A'),
(1, 17, 4, 1, 82, 'A'),
(1, 12, 4, 2, 77, 'A'),
(1, 16, 4, 2, 85, 'A'),
(1, 18, 4, 2, 70, 'A'),
(2, 1, 2, 1, 85, 'A'),
(2, 3, 2, 1, 72, 'A'),
(2, 5, 2, 1, 68, 'B'),
(2, 6, 2, 1, 74, 'A'),
(2, 2, 2, 2, 88, 'A'),
(2, 4, 2, 2, 71, 'A'),
(2, 7, 2, 2, 77, 'A'),
(2, 8, 3, 1, 79, 'A'),
(2, 10, 3, 1, 73, 'A'),
(2, 11, 3, 1, 64, 'B'),
(2, 9, 3, 2, 75, 'A'),
(2, 12, 3, 2, 81, 'A'),
(2, 13, 3, 2, 70, 'A'),
(2, 14, 3, 2, 66, 'B'),
(2, 15, 4, 1, 83, 'A'),
(2, 17, 4, 1, 78, 'A'),
(3, 1, 2, 1, 52, 'C'),
(3, 3, 2, 1, 48, 'D'),
(3, 5, 2, 1, 61, 'B'),
(3, 6, 2, 1, 58, 'C'),
(3, 2, 2, 2, 55, 'C'),
(3, 4, 2, 2, 42, 'E'),
(3, 7, 2, 2, 64, 'B'),
(3, 8, 3, 1, 57, 'C'),
(3, 10, 3, 1, 49, 'D'),
(3, 11, 3, 1, 51, 'C'),
(3, 9, 3, 2, 60, 'B'),
(3, 12, 3, 2, 53, 'C'),
(3, 13, 3, 2, 46, 'D'),
(3, 14, 3, 2, 38, 'F'),
(3, 15, 4, 1, 62, 'B'),
(3, 17, 4, 1, 59, 'C'),
(3, 16, 4, 2, 56, 'C'),
(3, 18, 4, 2, 67, 'B'),
(4, 1, 3, 1, 66, 'B'),
(4, 3, 3, 1, 70, 'A'),
(4, 5, 3, 1, 74, 'A'),
(4, 6, 3, 1, 63, 'B'),
(4, 2, 3, 2, 72, 'A'),
(4, 4, 3, 2, 61, 'B'),
(4, 7, 3, 2, 68, 'B'),
(4, 8, 4, 1, 69, 'B'),
(4, 10, 4, 1, 64, 'B'),
(4, 11, 4, 1, 58, 'C'),
(5, 1, 3, 1, 90, 'A'),
(5, 3, 3, 1, 84, 'A'),
(5, 5, 3, 1, 79, 'A'),
(5, 6, 3, 1, 86, 'A'),
(5, 2, 3, 2, 92, 'A'),
(5, 4, 3, 2, 80, 'A'),
(5, 7, 3, 2, 75, 'A'),
(5, 8, 4, 1, 88, 'A'),
(5, 10, 4, 1, 81, 'A'),
(5, 11, 4, 1, 77, 'A'),
(5, 9, 4, 2, 85, 'A'),
(5, 12, 4, 2, 91, 'A'),
(5, 13, 4, 2, 79, 'A'),
(5, 14, 4, 2, 72, 'A'),
(6, 1, 4, 1, 73, 'A'),
(6, 3, 4, 1, 59, 'C'),
(6, 5, 4, 1, 67, 'B'),
(6, 6, 4, 1, 71, 'A'),
(8, 1, 3, 1, 54, 'C'),
(8, 3, 3, 1, 47, 'D'),
(8, 5, 3, 1, 60, 'B'),
(8, 6, 3, 1, 65, 'B');

-- transcript requests (fee 5000.00 NGN copied onto each request)
-- open requests (pending_payment/paid/processing): max one per student
INSERT INTO transcript_requests (id, request_no, student_id, status, scope, session_id, fee_amount, purpose, rejection_reason, ready_at, transcript_no, total_units, gpa, cgpa, download_count, last_downloaded_at, created_at) VALUES
(1, 'TR-2026-000001', 1, 'ready',          'full',    NULL, 5000.00, 'Masters admission', NULL, '2026-09-03 09:30:00', 'UITR-2026-000001', 55, NULL, 4.5, 2, '2026-09-04 14:10:00', '2026-09-02 10:00:00'),
(2, 'TR-2026-000002', 1, 'pending_payment','full',    NULL, 5000.00, 'Employment',        NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, '2026-10-05 08:45:00'),
(3, 'TR-2026-000003', 2, 'pending_payment','full',    NULL, 5000.00, NULL,                NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, '2026-10-06 11:20:00'),
(4, 'TR-2026-000004', 3, 'paid',           'full',    NULL, 5000.00, 'Scholarship',       NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, '2026-10-03 13:05:00'),
(5, 'TR-2026-000005', 4, 'processing',     'full',    NULL, 5000.00, NULL,                NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, '2026-10-01 16:40:00'),
(6, 'TR-2026-000006', 5, 'rejected',       'full',    NULL, 5000.00, NULL, 'Outstanding departmental clearance', NULL, NULL, NULL, NULL, NULL, 0, NULL, '2026-09-28 09:00:00'),
(7, 'TR-2026-000007', 6, 'ready',          'session', 4, 5000.00, 'Internship', NULL, '2026-10-02 10:00:00', 'UITR-2026-000007', 11, 4.18, 4.18, 1, '2026-10-02 12:30:00', '2026-09-30 15:00:00');

-- payments (amount in naira; Paystack test-mode style references)
INSERT INTO payments (id, request_id, reference, gateway_txn_id, amount, status, failure_reason, raw_response, initiated_at, paid_at, verified_at) VALUES
(1, 1, 'PAY-2026-000001', '4100000001', 5000.00, 'success',   NULL, '{"status":"success","amount":500000,"currency":"NGN"}', '2026-09-02 10:10:00', '2026-09-02 10:15:00', '2026-09-02 10:16:00'),
(2, 3, 'PAY-2026-000002', NULL,         5000.00, 'failed',    'Insufficient funds', '{"status":"failed","gateway_response":"Insufficient funds"}', '2026-10-06 11:25:00', NULL, NULL),
(3, 3, 'PAY-2026-000003', NULL,         5000.00, 'initiated', NULL, NULL, '2026-10-06 11:40:00', NULL, NULL),
(4, 4, 'PAY-2026-000004', '4100000004', 5000.00, 'success',   NULL, '{"status":"success","amount":500000,"currency":"NGN"}', '2026-10-03 13:10:00', '2026-10-03 13:14:00', '2026-10-03 13:15:00'),
(5, 5, 'PAY-2026-000005', '4100000005', 5000.00, 'success',   NULL, '{"status":"success","amount":500000,"currency":"NGN"}', '2026-10-01 16:45:00', '2026-10-01 16:48:00', '2026-10-01 16:49:00'),
(6, 7, 'PAY-2026-000006', '4100000006', 5000.00, 'success',   NULL, '{"status":"success","amount":500000,"currency":"NGN"}', '2026-09-30 15:05:00', '2026-09-30 15:08:00', '2026-09-30 15:09:00');