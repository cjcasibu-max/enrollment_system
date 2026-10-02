<?php
require_once '../includes/lms_access.php';

$lmsStudent = requireLmsAccess(['student']);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}

$action = (string)($_POST['action'] ?? '');
$subjectId = filter_input(INPUT_POST, 'subject_id', FILTER_VALIDATE_INT);
$quizId = filter_input(INPUT_POST, 'quiz_id', FILTER_VALIDATE_INT);
$attemptId = filter_input(INPUT_POST, 'attempt_id', FILTER_VALIDATE_INT);
$studentId = (int)$lmsStudent['student_id'];
$isAsync = ($_POST['async'] ?? '') === '1';
$returnTo = $subjectId
    ? resolveAppUrl('student/lms_course?subject_id=' . (int)$subjectId . '&section=quizzes')
    : resolveAppUrl('student/lms');
$attemptUrl = static function (int $id, ?string $launchToken = null) use ($subjectId): string {
    $url = 'student/lms_quiz?subject_id=' . (int)$subjectId . '&attempt_id=' . $id;
    if ($launchToken !== null) {
        $url .= '&launch_token=' . rawurlencode($launchToken);
    }
    return resolveAppUrl($url);
};
$complete = static function (array $result = [], ?string $redirectUrl = null) use ($isAsync, $returnTo): void {
    if ($isAsync) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($result, JSON_UNESCAPED_SLASHES);
        exit;
    }
    if (isset($result['error'])) {
        $_SESSION['flash_error'] = $result['error'];
    } elseif (isset($result['success'])) {
        $_SESSION['flash_success'] = $result['success'];
    }
    header('Location: ' . ($redirectUrl ?? $returnTo));
    exit;
};

if (!$subjectId || !$quizId && $action === 'start' || !$attemptId && $action !== 'start') {
    $complete(['error' => 'Invalid quiz request.']);
}
if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
    $complete(['error' => 'Your session token expired. Please try again.']);
}

$finalizeAttempt = static function (int $id, int $targetQuizId, string $status) use ($pdo): array {
    $result = calculateLmsQuizAttemptScore($pdo, $targetQuizId, $id);
    $updateStmt = $pdo->prepare(
        'UPDATE lms_quiz_attempts
         SET status = :status, submitted_at = NOW(), score = :score, total_points = :total_points
         WHERE id = :id AND status = \'in_progress\''
    );
    $updateStmt->execute([
        'status' => $status,
        'score' => $result['score'],
        'total_points' => $result['total_points'],
        'id' => $id,
    ]);
    return $result;
};

$saveResponse = static function (int $targetQuizId, int $targetAttemptId, int $questionId, string $answerValue) use ($pdo): void {
    $questionStmt = $pdo->prepare(
        'SELECT question_type FROM lms_quiz_questions WHERE id = :question_id AND quiz_id = :quiz_id LIMIT 1'
    );
    $questionStmt->execute([
        'question_id' => $questionId,
        'quiz_id' => $targetQuizId,
    ]);
    $questionType = $questionStmt->fetchColumn();
    if (!$questionType) {
        throw new DomainException('That question is not part of this quiz.');
    }

    $choiceId = null;
    $answerText = null;
    if ($questionType === 'identification') {
        if (strlen($answerValue) > 10000) {
            throw new DomainException('That answer is too long.');
        }
        $answerText = trim($answerValue);
    } elseif ($answerValue !== '') {
        $choiceId = filter_var($answerValue, FILTER_VALIDATE_INT);
        if (!$choiceId || $choiceId < 1) {
            throw new DomainException('Choose one of the listed answers.');
        }
        $choiceStmt = $pdo->prepare(
            'SELECT id FROM lms_quiz_choices WHERE id = :choice_id AND question_id = :question_id LIMIT 1'
        );
        $choiceStmt->execute([
            'choice_id' => $choiceId,
            'question_id' => $questionId,
        ]);
        if (!$choiceStmt->fetchColumn()) {
            throw new DomainException('That answer is not available for this question.');
        }
    }

    if (($questionType === 'identification' && $answerText === '') || ($questionType !== 'identification' && $answerValue === '')) {
        $deleteStmt = $pdo->prepare(
            'DELETE FROM lms_quiz_responses WHERE attempt_id = :attempt_id AND question_id = :question_id'
        );
        $deleteStmt->execute([
            'attempt_id' => $targetAttemptId,
            'question_id' => $questionId,
        ]);
        return;
    }

    $responseStmt = $pdo->prepare(
        'INSERT INTO lms_quiz_responses (attempt_id, question_id, choice_id, answer_text, answered_at)
         VALUES (:attempt_id, :question_id, :choice_id, :answer_text, NOW())
         ON DUPLICATE KEY UPDATE choice_id = VALUES(choice_id), answer_text = VALUES(answer_text), answered_at = NOW()'
    );
    $responseStmt->execute([
        'attempt_id' => $targetAttemptId,
        'question_id' => $questionId,
        'choice_id' => $choiceId,
        'answer_text' => $answerText,
    ]);
};

try {
    if ($action === 'start') {
        $pdo->beginTransaction();
        $quiz = fetchLmsQuizForStudentSubject($pdo, $studentId, $subjectId, $quizId, true);
        if (!$quiz) {
            throw new DomainException('This quiz is not available in your enrolled subject.');
        }
        if (empty($quiz['is_available'])) {
            throw new DomainException('This quiz is not currently open.');
        }

        $questionCountStmt = $pdo->prepare('SELECT COUNT(*) FROM lms_quiz_questions WHERE quiz_id = :quiz_id');
        $questionCountStmt->execute(['quiz_id' => $quizId]);
        if ((int)$questionCountStmt->fetchColumn() < 1) {
            throw new DomainException('This quiz has no questions yet.');
        }

        $activeStmt = $pdo->prepare(
            "SELECT id, deadline_at, CASE WHEN deadline_at <= NOW() THEN 1 ELSE 0 END AS is_expired
             FROM lms_quiz_attempts
             WHERE quiz_id = :quiz_id AND student_id = :student_id AND status = 'in_progress'
             ORDER BY attempt_number DESC
             LIMIT 1 FOR UPDATE"
        );
        $activeStmt->execute([
            'quiz_id' => $quizId,
            'student_id' => $studentId,
        ]);
        $activeAttempt = $activeStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($activeAttempt) {
            $activeId = (int)$activeAttempt['id'];
            if (empty($activeAttempt['is_expired']) && !empty($_SESSION['lms_quiz_active_attempts'][$activeId])) {
                $pdo->commit();
                $complete(['error' => 'You already have an active quiz attempt. Return to its open quiz page.']);
            }
            $interruptedStatus = !empty($activeAttempt['is_expired']) ? 'timed_out' : 'interrupted';
            $finalizeAttempt($activeId, $quizId, $interruptedStatus);
            unset($_SESSION['lms_quiz_active_attempts'][$activeId]);
        }

        $attemptCountStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM lms_quiz_attempts WHERE quiz_id = :quiz_id AND student_id = :student_id'
        );
        $attemptCountStmt->execute([
            'quiz_id' => $quizId,
            'student_id' => $studentId,
        ]);
        $attemptCount = (int)$attemptCountStmt->fetchColumn();
        if ($attemptCount >= (int)$quiz['allowed_attempts']) {
            $pdo->commit();
            $complete(['error' => 'You have used all allowed attempts for this quiz.']);
        }

        $insertStmt = $pdo->prepare(
            'INSERT INTO lms_quiz_attempts (quiz_id, student_id, attempt_number, status, started_at, deadline_at)
             SELECT q.id, :student_id, :attempt_number, \'in_progress\', NOW(),
                    CASE WHEN q.closes_at IS NOT NULL AND q.closes_at < TIMESTAMPADD(MINUTE, :close_limit, NOW())
                         THEN q.closes_at ELSE TIMESTAMPADD(MINUTE, :time_limit, NOW()) END
             FROM lms_quizzes q WHERE q.id = :quiz_id'
        );
        $insertStmt->execute([
            'student_id' => $studentId,
            'attempt_number' => $attemptCount + 1,
            'close_limit' => (int)$quiz['time_limit_minutes'],
            'time_limit' => (int)$quiz['time_limit_minutes'],
            'quiz_id' => $quizId,
        ]);
        $newAttemptId = (int)$pdo->lastInsertId();
        $pdo->commit();
        $launchToken = bin2hex(random_bytes(24));
        $_SESSION['lms_quiz_launch_tokens'][$newAttemptId] = $launchToken;
        $_SESSION['lms_quiz_active_attempts'][$newAttemptId] = true;
        $complete([], $attemptUrl($newAttemptId, $launchToken));
    }

    if ($action === 'save_response' || $action === 'submit' || $action === 'interrupt') {
        $attempt = fetchLmsQuizAttemptForStudentSubject($pdo, $studentId, $subjectId, $attemptId);
        if (!$attempt) {
            throw new DomainException('This quiz attempt is not available to your account.');
        }
        if ($attempt['status'] !== 'in_progress') {
            unset($_SESSION['lms_quiz_active_attempts'][$attemptId]);
            $complete(
                $isAsync ? ['ok' => false, 'finished' => true, 'message' => 'This quiz attempt is already closed.'] : ['success' => 'This quiz attempt is already closed.'],
                $isAsync ? null : $attemptUrl($attemptId)
            );
        }
        if ($action !== 'interrupt' && empty($_SESSION['lms_quiz_active_attempts'][$attemptId])) {
            throw new DomainException('Interrupted attempts cannot be resumed.');
        }

        $pdo->beginTransaction();
        $lockStmt = $pdo->prepare(
            "SELECT quiz_id, deadline_at, CASE WHEN deadline_at <= NOW() THEN 1 ELSE 0 END AS is_expired
             FROM lms_quiz_attempts
             WHERE id = :attempt_id AND student_id = :student_id AND status = 'in_progress'
             LIMIT 1 FOR UPDATE"
        );
        $lockStmt->execute([
            'attempt_id' => $attemptId,
            'student_id' => $studentId,
        ]);
        $lockedAttempt = $lockStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$lockedAttempt || (int)$lockedAttempt['quiz_id'] !== (int)$attempt['quiz_id']) {
            throw new DomainException('This quiz attempt is no longer active.');
        }

        if ($action === 'save_response') {
            if (!empty($lockedAttempt['is_expired'])) {
                $finalizeAttempt($attemptId, (int)$attempt['quiz_id'], 'timed_out');
                $pdo->commit();
                unset($_SESSION['lms_quiz_active_attempts'][$attemptId]);
                $complete([
                    'ok' => false,
                    'finished' => true,
                    'message' => 'Time expired. Your saved answers were submitted.',
                    'result_url' => $attemptUrl($attemptId),
                ]);
            }
            $questionId = filter_var($_POST['question_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$questionId) {
                throw new DomainException('Invalid quiz question.');
            }
            $saveResponse((int)$attempt['quiz_id'], $attemptId, (int)$questionId, (string)($_POST['answer_value'] ?? ''));
            $pdo->commit();
            $complete(['ok' => true]);
        }

        if ($action === 'interrupt') {
            $status = !empty($lockedAttempt['is_expired']) ? 'timed_out' : 'interrupted';
            $finalizeAttempt($attemptId, (int)$attempt['quiz_id'], $status);
            $pdo->commit();
            unset($_SESSION['lms_quiz_active_attempts'][$attemptId]);
            $complete(
                $isAsync ? ['ok' => true, 'finished' => true] : ['success' => 'The interrupted attempt has been submitted and counted.'],
                $isAsync ? null : $returnTo
            );
        }

        if (!empty($lockedAttempt['is_expired'])) {
            $submissionStatus = 'timed_out';
        } else {
            $answers = $_POST['answers'] ?? [];
            if (is_array($answers)) {
                foreach ($answers as $questionId => $answerValue) {
                    $validatedQuestionId = filter_var($questionId, FILTER_VALIDATE_INT);
                    if (!$validatedQuestionId || !is_scalar($answerValue)) {
                        continue;
                    }
                    $saveResponse((int)$attempt['quiz_id'], $attemptId, (int)$validatedQuestionId, (string)$answerValue);
                }
            }
            $submissionStatus = 'submitted';
        }
        $finalizeAttempt($attemptId, (int)$attempt['quiz_id'], $submissionStatus);
        $pdo->commit();
        unset($_SESSION['lms_quiz_active_attempts'][$attemptId]);
        $complete(
            ['success' => $submissionStatus === 'timed_out' ? 'Time expired. Your saved answers were submitted.' : 'Your quiz was submitted.'],
            $attemptUrl($attemptId)
        );
    }

    throw new DomainException('Unknown quiz action.');
} catch (DomainException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $complete($isAsync
        ? ['ok' => false, 'message' => $e->getMessage()]
        : ['error' => $e->getMessage()]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('LMS quiz action failed: ' . $e->getMessage());
    $complete($isAsync
        ? ['ok' => false, 'message' => 'The quiz request could not be saved.']
        : ['error' => 'The quiz request could not be saved. Please try again.']);
}