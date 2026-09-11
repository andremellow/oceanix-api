-- PostgreSQL. One-off operation explicitly requested by the owner.
-- Run the ENTIRE file in one session, with application writes/workers paused.
-- Take a database snapshot first: the final COMMIT makes the demo purge permanent.
-- Scope: ALL currently shared courses/modules; demo=1, new owner=2.
-- Does not delete users, companies, course content, or files in object storage.
-- Existing shared image-library assets remain available; their URLs are unchanged.
-- Public preview links for transferred courses are expired.
BEGIN;
SET LOCAL lock_timeout = '10s';
SET LOCAL statement_timeout = '120s';

LOCK TABLE companies, courses, course_versions, lessons, course_version_lessons,
    videos, questions, question_options, company_courses,
    user_training_assignments, training_requirements, training_requirement_targets,
    course_attempts, lesson_attempts, question_attempts, lesson_progress,
    compliance_events, certificates, notifications, notification_deliveries,
    audit_logs, shared_content_propagations, shared_content_propagation_items,
    course_preview_links IN SHARE ROW EXCLUSIVE MODE;

CREATE TEMP TABLE move_courses ON COMMIT DROP AS
SELECT id FROM courses WHERE is_shared = TRUE AND company_id IS NULL;
CREATE TEMP TABLE move_versions ON COMMIT DROP AS
SELECT id FROM course_versions WHERE course_id IN (SELECT id FROM move_courses);
CREATE TEMP TABLE move_modules ON COMMIT DROP AS
SELECT id FROM lessons WHERE is_shared = TRUE AND company_id IS NULL;
CREATE TEMP TABLE demo_assignments ON COMMIT DROP AS
SELECT id FROM user_training_assignments
WHERE company_id = 1 AND course_id IN (SELECT id FROM move_courses);
CREATE TEMP TABLE demo_requirements ON COMMIT DROP AS
SELECT id FROM training_requirements
WHERE company_id = 1 AND course_id IN (SELECT id FROM move_courses);
CREATE TEMP TABLE demo_course_attempts ON COMMIT DROP AS
SELECT id FROM course_attempts WHERE assignment_id IN (SELECT id FROM demo_assignments);
CREATE TEMP TABLE demo_lesson_attempts ON COMMIT DROP AS
SELECT id FROM lesson_attempts WHERE course_attempt_id IN (SELECT id FROM demo_course_attempts);

DO $$
BEGIN
    IF (SELECT count(*) FROM companies WHERE id IN (1, 2)) <> 2 THEN
        RAISE EXCEPTION 'Companies 1 and 2 must exist';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM move_courses) THEN
        RAISE EXCEPTION 'No shared courses found; this script is not intended to be rerun';
    END IF;
    IF (SELECT count(*) FROM demo_assignments) <> 11
       OR (SELECT count(*) FROM user_training_assignments WHERE id IN (SELECT id FROM demo_assignments) AND status = 'completed') <> 2
       OR (SELECT count(*) FROM user_training_assignments WHERE id IN (SELECT id FROM demo_assignments) AND status = 'in_progress') <> 6
       OR (SELECT count(*) FROM user_training_assignments WHERE id IN (SELECT id FROM demo_assignments) AND status = 'cancelled') <> 3
       OR (SELECT count(*) FROM demo_requirements) <> 1
       OR (SELECT count(*) FROM training_requirements WHERE id IN (SELECT id FROM demo_requirements) AND status = 'active') <> 1 THEN
        RAISE EXCEPTION 'Demo data changed: expected 2 completed, 6 in_progress, 3 cancelled assignments and 1 active requirement';
    END IF;
    IF EXISTS (SELECT 1 FROM company_courses WHERE course_id IN (SELECT id FROM move_courses) AND company_id NOT IN (1, 2))
       OR EXISTS (SELECT 1 FROM user_training_assignments WHERE course_id IN (SELECT id FROM move_courses) AND company_id NOT IN (1, 2))
       OR EXISTS (SELECT 1 FROM training_requirements WHERE course_id IN (SELECT id FROM move_courses) AND company_id NOT IN (1, 2)) THEN
        RAISE EXCEPTION 'Another company references these courses';
    END IF;
    IF EXISTS (
        SELECT 1 FROM course_version_lessons cvl
        JOIN course_versions cv ON cv.id = cvl.course_version_id
        JOIN courses c ON c.id = cv.course_id
        WHERE cvl.lesson_id IN (SELECT id FROM move_modules)
          AND c.id NOT IN (SELECT id FROM move_courses) AND c.company_id IS DISTINCT FROM 2
    ) OR EXISTS (
        SELECT 1 FROM lessons l JOIN course_versions cv ON cv.id = l.course_version_id
        JOIN courses c ON c.id = cv.course_id
        WHERE l.id IN (SELECT id FROM move_modules)
          AND c.id NOT IN (SELECT id FROM move_courses) AND c.company_id IS DISTINCT FROM 2
    ) OR EXISTS (
        SELECT 1 FROM lessons l WHERE l.id NOT IN (SELECT id FROM move_modules)
        AND l.company_id IS DISTINCT FROM 2 AND (
            l.course_version_id IN (SELECT id FROM move_versions)
            OR l.id IN (SELECT lesson_id FROM course_version_lessons WHERE course_version_id IN (SELECT id FROM move_versions))
        )
    ) THEN
        RAISE EXCEPTION 'Module composition crosses the destination ownership boundary';
    END IF;
    IF EXISTS (SELECT 1 FROM courses s JOIN courses t ON t.code = s.code
               WHERE s.id IN (SELECT id FROM move_courses) AND t.company_id = 2 AND NOT t.is_shared)
       OR EXISTS (SELECT 1 FROM lessons s JOIN lessons t ON t.code = s.code AND t.version_number = s.version_number
                  WHERE s.id IN (SELECT id FROM move_modules) AND t.company_id = 2 AND NOT t.is_shared) THEN
        RAISE EXCEPTION 'Course/module codes collide with existing company 2 content';
    END IF;
    IF EXISTS (SELECT 1 FROM shared_content_propagations WHERE status IN ('pending', 'processing'))
       OR EXISTS (SELECT 1 FROM shared_content_propagation_items WHERE status IN ('pending', 'processing')) THEN
        RAISE EXCEPTION 'Finish pending content propagation before transferring ownership';
    END IF;
END $$;

-- Save every other tenant's operational row and check it again before COMMIT.
-- This also detects accidental cross-tenant cascades / SET NULL references.
CREATE TEMP TABLE protected_rows (table_name text, row_id bigint, row_data jsonb) ON COMMIT DROP;
DO $$
DECLARE t text;
BEGIN
    FOREACH t IN ARRAY ARRAY['user_training_assignments', 'training_requirements',
        'training_requirement_targets', 'course_attempts', 'lesson_attempts',
        'question_attempts', 'lesson_progress', 'compliance_events', 'certificates',
        'notifications', 'notification_deliveries', 'audit_logs', 'company_courses'] LOOP
        EXECUTE format('INSERT INTO protected_rows SELECT %L, id, to_jsonb(r) FROM %I r WHERE company_id IS DISTINCT FROM 1', t, t);
    END LOOP;
END $$;

-- Events use SET NULL, so remove the demo evidence BEFORE deleting assignments.
DELETE FROM compliance_events WHERE company_id = 1 AND (
    assignment_id IN (SELECT id FROM demo_assignments)
    OR course_version_id IN (SELECT id FROM move_versions)
    OR lesson_id IN (SELECT id FROM move_modules)
    OR course_attempt_id IN (SELECT id FROM demo_course_attempts)
    OR lesson_attempt_id IN (SELECT id FROM demo_lesson_attempts)
    OR question_id IN (SELECT id FROM questions WHERE lesson_id IN (SELECT id FROM move_modules))
);

-- Remove typed administrative records for the demo objects being purged.
-- Unrelated audit records, including platform history, remain intact.
DELETE FROM audit_logs WHERE company_id = 1 AND (
    (auditable_type = 'App\Models\UserTrainingAssignment' AND auditable_id IN (SELECT id FROM demo_assignments))
    OR (auditable_type = 'App\Models\TrainingRequirement' AND auditable_id IN (SELECT id FROM demo_requirements))
    OR (auditable_type = 'App\Models\Certificate' AND auditable_id IN (SELECT id FROM certificates WHERE assignment_id IN (SELECT id FROM demo_assignments)))
    OR (auditable_type = 'App\Models\CompanyCourse' AND auditable_id IN (SELECT id FROM company_courses WHERE company_id = 1 AND course_id IN (SELECT id FROM move_courses)))
);

-- Verified schema cascades remove attempts, answers, progress, certificates,
-- assignment notifications and notification deliveries; targets cascade from rules.
DELETE FROM user_training_assignments WHERE company_id = 1 AND id IN (SELECT id FROM demo_assignments);
DELETE FROM training_requirements WHERE company_id = 1 AND id IN (SELECT id FROM demo_requirements);
DELETE FROM company_courses WHERE company_id = 1 AND course_id IN (SELECT id FROM move_courses);

UPDATE courses SET is_shared = FALSE, company_id = 2, updated_at = CURRENT_TIMESTAMP
WHERE id IN (SELECT id FROM move_courses);
UPDATE course_versions SET company_id = 2, updated_at = CURRENT_TIMESTAMP
WHERE id IN (SELECT id FROM move_versions);
UPDATE lessons SET is_shared = FALSE, company_id = 2, updated_at = CURRENT_TIMESTAMP
WHERE id IN (SELECT id FROM move_modules);
UPDATE videos SET company_id = 2, updated_at = CURRENT_TIMESTAMP
WHERE lesson_id IN (SELECT id FROM move_modules);
UPDATE questions SET company_id = 2, updated_at = CURRENT_TIMESTAMP
WHERE lesson_id IN (SELECT id FROM move_modules);
UPDATE question_options SET company_id = 2, updated_at = CURRENT_TIMESTAMP
WHERE question_id IN (SELECT id FROM questions WHERE lesson_id IN (SELECT id FROM move_modules));
UPDATE course_preview_links SET expires_at = LEAST(expires_at, CURRENT_TIMESTAMP), updated_at = CURRENT_TIMESTAMP
WHERE course_version_id IN (SELECT id FROM move_versions);

DO $$
DECLARE t text; changed boolean;
BEGIN
    FOR t IN SELECT DISTINCT table_name FROM protected_rows LOOP
        EXECUTE format('SELECT EXISTS (SELECT 1 FROM protected_rows p LEFT JOIN %I r ON r.id = p.row_id WHERE p.table_name = %L AND p.row_data IS DISTINCT FROM to_jsonb(r))', t, t) INTO changed;
        IF changed THEN
            RAISE EXCEPTION 'Aborting: another tenant''s data changed in %', t;
        END IF;
    END LOOP;
END $$;

SELECT (SELECT count(*) FROM move_courses) AS courses_transferred,
       (SELECT count(*) FROM move_versions) AS course_versions_transferred,
       (SELECT count(*) FROM move_modules) AS module_versions_transferred,
       (SELECT count(*) FROM demo_assignments) AS demo_assignments_deleted,
       (SELECT count(*) FROM demo_requirements) AS demo_requirements_deleted;

COMMIT;
