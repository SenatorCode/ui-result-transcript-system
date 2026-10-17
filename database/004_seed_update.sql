-- =============================================================
-- 004_seed_update.sql   (FAKE DATA ONLY)
-- Run after 003_backend_alignment.sql, once.
-- Updates the sample data from 002 for the new statuses and columns, and adds examples
-- of the new states. 002 is left untouched.
--
-- Request/state coverage after this file
--   TR-2026-000001 ready (personal)                TR-2026-000008 sent (institution, email, 2 copies)
--   TR-2026-000002 pending_payment                 TR-2026-000009 cancelled
--   TR-2026-000003 payment_processing              TR-2026-000010 expired
--   TR-2026-000004 paid                            TR-2026-000011 failed (declined payment)
--   TR-2026-000005 processing
--   TR-2026-000006 rejected
--   TR-2026-000007 ready (personal, stored as session scope in 002; backend always writes 'full')
--
-- Fees below are placeholders until the group agrees the fee formula
-- (5000.00 per copy is used here).
-- =============================================================

USE transcript_system;

-- Request 3 has an initiated payment attempt, so it is mid-payment.
UPDATE transcript_requests SET status = 'payment_processing' WHERE id = 3;

-- Unpaid requests get a payment deadline (30 days from import, so demos do not expire).
UPDATE transcript_requests
   SET expires_at = NOW() + INTERVAL 30 DAY
 WHERE id IN (2, 3);

-- The seeded payments are all demo payments.
UPDATE payments SET gateway = 'simulated';

-- New sample requests (none of these students has another open request).
INSERT INTO transcript_requests
    (id, request_no, student_id, status, scope, session_id, fee_amount, purpose, rejection_reason,
     destination_type, institution_name, destination_address, destination_email, delivery_method, copies,
     ready_at, sent_at, expires_at, transcript_no, total_units, cgpa, created_at)
VALUES
-- 8: sent to an institution by email (issued record computed from the student's real results)
(8, 'TR-2026-000008', 5, 'sent', 'full', NULL, 10000.00, 'Postgraduate application', NULL,
    'institution', 'Example University', NULL, 'records@example.edu', 'email', 2,
    '2026-10-08 10:00:00', '2026-10-08 11:00:00', NULL, 'UITR-2026-000008',
    (SELECT SUM(unit) FROM v_result_details WHERE student_id = 5),
    (SELECT ROUND(SUM(quality_points) / SUM(unit), 2) FROM v_counted_results WHERE student_id = 5),
    '2026-10-07 09:00:00'),
-- 9: cancelled by the student before paying
(9, 'TR-2026-000009', 7, 'cancelled', 'full', NULL, 5000.00, NULL, NULL,
    'personal', NULL, NULL, NULL, 'download', 1,
    NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-02 14:00:00'),
-- 10: quote/payment deadline passed
(10, 'TR-2026-000010', 7, 'expired', 'full', NULL, 5000.00, NULL, NULL,
    'personal', NULL, NULL, NULL, 'download', 1,
    NULL, NULL, '2026-09-21 14:00:00', NULL, NULL, NULL, '2026-09-20 14:00:00'),
-- 11: payment attempt declined; the student can retry or cancel
(11, 'TR-2026-000011', 6, 'failed', 'full', NULL, 5000.00, NULL, NULL,
    'personal', NULL, NULL, NULL, 'download', 1,
    NULL, NULL, NOW() + INTERVAL 30 DAY, NULL, NULL, NULL, '2026-10-09 09:30:00');

INSERT INTO payments
    (id, request_id, reference, gateway, gateway_txn_id, amount, status, failure_reason,
     raw_response, initiated_at, paid_at, verified_at)
VALUES
(7, 8, 'PAY-2026-000007', 'simulated', '4100000007', 10000.00, 'success', NULL,
    '{"status":"success","mode":"simulated"}', '2026-10-07 09:10:00', '2026-10-07 09:12:00', '2026-10-07 09:13:00'),
(8, 11, 'PAY-2026-000008', 'simulated', NULL, 5000.00, 'failed', 'Card declined',
    '{"status":"failed","mode":"simulated","gateway_response":"Card declined"}', '2026-10-09 09:35:00', NULL, NULL);

-- Receipt numbers for every verified payment: RCPT-<year>-<1000 + payment id>.
UPDATE payments
   SET receipt_no = CONCAT('RCPT-2026-', 1000 + id)
 WHERE status = 'success' AND receipt_no IS NULL;