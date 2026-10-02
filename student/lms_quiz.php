<?php
require_once '../includes/lms_access.php';

$lmsStudent = requireLmsAccess(['student']);
$subjectId = filter_input(INPUT_GET, 'subject_id', FILTER_VALIDATE_INT);
$attemptId = filter_input(INPUT_GET, 'attempt_id', FILTER_VALIDATE_INT);
$course = $subjectId
    ? fetchLmsSubjectForStudent($pdo, (int)$lmsStudent['student_id'], $subjectId)
    : null;

if (!$course || !$attemptId) {
    http_response_code(404);
    exit('Quiz attempt not found.');
}

$attempt = fetchLmsQuizAttemptForStudentSubject(
    $pdo,
    (int)$lmsStudent['student_id'],
    (int)$course['subject_id'],
    $attemptId
);
if (!$attempt) {
    http_response_code(404);
    exit('Quiz attempt not found.');
}

$canTakeQuiz = false;
$launchToken = $_GET['launch_token'] ?? null;
$sessionLaunchToken = $_SESSION['lms_quiz_launch_tokens'][$attemptId] ?? null;
if ($attempt['status'] === 'in_progress'
    && !empty($_SESSION['lms_quiz_active_attempts'][$attemptId])
    && is_string($launchToken)
    && is_string($sessionLaunchToken)
    && hash_equals($sessionLaunchToken, $launchToken)) {
    unset($_SESSION['lms_quiz_launch_tokens'][$attemptId]);
    $canTakeQuiz = true;
}

$quizQuestions = [];
$savedResponses = [];
if ($canTakeQuiz && (int)$attempt['seconds_remaining'] > 0) {
    $quizQuestions = fetchLmsQuizQuestionsForStudent($pdo, (int)$attempt['quiz_id']);
    $savedResponses = fetchLmsQuizResponsesForAttempt($pdo, $attemptId);
}

$page_title = $attempt['quiz_title'];
require_once '../includes/header.php';
?>

<p class="mb-3"><a href="lms_course?subject_id=<?php echo (int)$course['subject_id']; ?>&amp;section=quizzes" class="text-decoration-none"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Quizzes / Exams</a></p>
<div class="mb-4">
    <div class="text-uppercase small fw-bold text-brand-primary"><?php echo htmlspecialchars($course['subject_code']); ?></div>
    <h1 class="h3 fw-bold text-navy-alt mt-1 mb-1"><?php echo htmlspecialchars($attempt['quiz_title']); ?></h1>
    <p class="text-muted mb-0">Attempt <?php echo (int)$attempt['attempt_number']; ?> · <?php echo (int)$attempt['time_limit_minutes']; ?> minute limit</p>
</div>

<?php if ($canTakeQuiz && (int)$attempt['seconds_remaining'] > 0): ?>
    <div class="d-flex justify-content-between align-items-center border-bottom pb-3 mb-4 gap-3">
        <span class="fw-semibold">Time remaining</span>
        <span id="quiz-countdown" class="font-monospace fw-bold fs-5" role="timer" aria-live="off"></span>
    </div>
    <?php if (!empty($attempt['instructions'])): ?>
        <div class="mb-4"><?php echo nl2br(htmlspecialchars($attempt['instructions'])); ?></div>
    <?php endif; ?>
    <?php if (!$quizQuestions): ?>
        <div class="alert alert-warning">This quiz has no questions available. Your attempt has still started and will count toward the attempt limit.</div>
    <?php else: ?>
        <form id="lms-quiz-form" action="../actions/lms_quiz_actions" method="post">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
            <input type="hidden" name="action" value="submit">
            <input type="hidden" name="subject_id" value="<?php echo (int)$course['subject_id']; ?>">
            <input type="hidden" name="quiz_id" value="<?php echo (int)$attempt['quiz_id']; ?>">
            <input type="hidden" name="attempt_id" value="<?php echo (int)$attemptId; ?>">
            <div id="quiz-save-status" class="small text-muted mb-3" role="status" aria-live="polite">Answers save automatically as you work.</div>

            <?php foreach ($quizQuestions as $index => $question): ?>
                <?php $savedResponse = $savedResponses[(int)$question['id']] ?? null; ?>
                <fieldset class="border-bottom pb-4 mb-4">
                    <legend class="h5 fw-semibold mb-3">
                        <span class="text-muted me-1"><?php echo $index + 1; ?>.</span>
                        <?php echo nl2br(htmlspecialchars($question['prompt'])); ?>
                        <span class="small text-muted fw-normal">(<?php echo htmlspecialchars((string)$question['points']); ?> pt<?php echo (float)$question['points'] === 1.0 ? '' : 's'; ?>)</span>
                    </legend>
                    <?php if ($question['type'] === 'identification'): ?>
                        <label class="form-label" for="quiz-answer-<?php echo (int)$question['id']; ?>">Your answer</label>
                        <textarea class="form-control" id="quiz-answer-<?php echo (int)$question['id']; ?>" name="answers[<?php echo (int)$question['id']; ?>]" rows="2" data-quiz-question="<?php echo (int)$question['id']; ?>"><?php echo htmlspecialchars((string)($savedResponse['answer_text'] ?? '')); ?></textarea>
                    <?php elseif ($question['choices']): ?>
                        <?php foreach ($question['choices'] as $choice): ?>
                            <?php $choiceInputId = 'quiz-' . (int)$question['id'] . '-choice-' . (int)$choice['id']; ?>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="radio" name="answers[<?php echo (int)$question['id']; ?>]" id="<?php echo $choiceInputId; ?>" value="<?php echo (int)$choice['id']; ?>" data-quiz-question="<?php echo (int)$question['id']; ?>" <?php echo (int)($savedResponse['choice_id'] ?? 0) === (int)$choice['id'] ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="<?php echo $choiceInputId; ?>"><?php echo htmlspecialchars($choice['text']); ?></label>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="small text-danger mb-0">Answer options are not available for this question.</p>
                    <?php endif; ?>
                </fieldset>
            <?php endforeach; ?>

            <button class="btn btn-brand-primary" type="submit" data-quiz-submit>
                <i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Submit quiz
            </button>
        </form>
    <?php endif; ?>
<?php elseif ($attempt['status'] === 'in_progress' && $canTakeQuiz): ?>
    <div class="alert alert-warning">Time expired. Your saved answers are being submitted.</div>
    <form id="quiz-expired-form" action="../actions/lms_quiz_actions" method="post">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
        <input type="hidden" name="action" value="submit">
        <input type="hidden" name="subject_id" value="<?php echo (int)$course['subject_id']; ?>">
        <input type="hidden" name="quiz_id" value="<?php echo (int)$attempt['quiz_id']; ?>">
        <input type="hidden" name="attempt_id" value="<?php echo (int)$attemptId; ?>">
        <button class="btn btn-brand-primary" type="submit">Submit saved answers</button>
    </form>
    <script>document.getElementById('quiz-expired-form').requestSubmit();</script>
<?php elseif ($attempt['status'] === 'in_progress'): ?>
    <div class="alert alert-warning">This attempt was interrupted. It cannot be resumed; its saved answers will be submitted and the attempt will be consumed.</div>
    <form id="quiz-interrupted-form" action="../actions/lms_quiz_actions" method="post">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
        <input type="hidden" name="action" value="interrupt">
        <input type="hidden" name="subject_id" value="<?php echo (int)$course['subject_id']; ?>">
        <input type="hidden" name="quiz_id" value="<?php echo (int)$attempt['quiz_id']; ?>">
        <input type="hidden" name="attempt_id" value="<?php echo (int)$attemptId; ?>">
        <button class="btn btn-brand-primary" type="submit">Submit saved answers</button>
    </form>
    <script>document.getElementById('quiz-interrupted-form').requestSubmit();</script>
<?php else: ?>
    <?php
        $attemptStatusLabels = [
            'submitted' => 'Submitted',
            'interrupted' => 'Interrupted and submitted',
            'timed_out' => 'Time expired',
        ];
        $attemptStatusLabel = $attemptStatusLabels[$attempt['status']] ?? 'Completed';
    ?>
    <section class="border rounded bg-white p-4 p-lg-5" aria-labelledby="quiz-result-heading">
        <h2 class="h5 fw-bold text-navy-alt mb-3" id="quiz-result-heading"><?php echo htmlspecialchars($attemptStatusLabel); ?></h2>
        <p class="display-6 fw-bold mb-1"><?php echo htmlspecialchars((string)$attempt['score']); ?> <span class="fs-4 text-muted">/ <?php echo htmlspecialchars((string)$attempt['total_points']); ?></span></p>
        <?php if (!empty($attempt['passing_score']) || (isset($attempt['passing_score']) && is_numeric($attempt['passing_score']))): ?>
            <?php $isPassed = (float)($attempt['score'] ?? 0) >= (float)$attempt['passing_score']; ?>
            <div class="mb-3">
                <span class="badge <?php echo $isPassed ? 'text-bg-success' : 'text-bg-danger'; ?> fs-6">
                    <i class="bi <?php echo $isPassed ? 'bi-check-circle' : 'bi-x-circle'; ?> me-1"></i>
                    <?php echo $isPassed ? 'Passed' : 'Failed'; ?> (Passing score: <?php echo htmlspecialchars((string)$attempt['passing_score']); ?> pts)
                </span>
            </div>
        <?php endif; ?>
        <?php if ($attempt['status'] === 'interrupted'): ?>
            <p class="text-muted mb-0">The attempt was consumed when it was interrupted. Only answers saved before interruption were scored.</p>
        <?php elseif ($attempt['status'] === 'timed_out'): ?>
            <p class="text-muted mb-0">The time limit ended. Saved answers were scored and this attempt counts toward your limit.</p>
        <?php else: ?>
            <p class="text-muted mb-0">Your submitted answers have been scored.</p>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php if ($canTakeQuiz && (int)$attempt['seconds_remaining'] > 0 && $quizQuestions): ?>
    <script>
        (() => {
            const form = document.getElementById('lms-quiz-form');
            const countdown = document.getElementById('quiz-countdown');
            const saveStatus = document.getElementById('quiz-save-status');
            const attemptId = <?php echo (int)$attemptId; ?>;
            const subjectId = <?php echo (int)$course['subject_id']; ?>;
            const quizId = <?php echo (int)$attempt['quiz_id']; ?>;
            const csrfToken = form.querySelector('[name="csrf_token"]').value;
            const actionUrl = '../actions/lms_quiz_actions';
            let remainingSeconds = Math.max(0, <?php echo (int)$attempt['seconds_remaining']; ?>);
            let submitting = false;
            const pendingSaves = new Map();

            const displayTime = () => {
                const minutes = Math.floor(remainingSeconds / 60).toString().padStart(2, '0');
                const seconds = (remainingSeconds % 60).toString().padStart(2, '0');
                countdown.textContent = `${minutes}:${seconds}`;
            };
            const submitQuiz = () => {
                if (submitting) return;
                submitting = true;
                form.requestSubmit(form.querySelector('[data-quiz-submit]'));
            };
            const saveAnswer = async (field) => {
                const data = new FormData();
                data.append('action', 'save_response');
                data.append('async', '1');
                data.append('csrf_token', csrfToken);
                data.append('subject_id', subjectId);
                data.append('quiz_id', quizId);
                data.append('attempt_id', attemptId);
                data.append('question_id', field.dataset.quizQuestion);
                data.append('answer_value', field.value);
                try {
                    const response = await fetch(actionUrl, { method: 'POST', body: data, credentials: 'same-origin', keepalive: true });
                    const result = await response.json();
                    if (result.finished && result.result_url) {
                        submitting = true;
                        window.location.assign(result.result_url);
                        return;
                    }
                    saveStatus.textContent = result.ok ? 'All changes saved.' : (result.message || 'Answer could not be saved.');
                    saveStatus.classList.toggle('text-danger', !result.ok);
                } catch (error) {
                    saveStatus.textContent = 'Connection interrupted. Your last saved answers will be submitted.';
                    saveStatus.classList.add('text-danger');
                }
            };

            form.addEventListener('change', (event) => {
                if (event.target.matches('[data-quiz-question]')) saveAnswer(event.target);
            });
            form.addEventListener('input', (event) => {
                const field = event.target;
                if (!field.matches('textarea[data-quiz-question]')) return;
                const questionId = field.dataset.quizQuestion;
                window.clearTimeout(pendingSaves.get(questionId));
                pendingSaves.set(questionId, window.setTimeout(() => saveAnswer(field), 400));
            });
            form.addEventListener('submit', () => { submitting = true; });
            window.addEventListener('pagehide', () => {
                if (submitting || remainingSeconds <= 0) return;
                const data = new FormData();
                data.append('action', 'interrupt');
                data.append('async', '1');
                data.append('csrf_token', csrfToken);
                data.append('subject_id', subjectId);
                data.append('quiz_id', quizId);
                data.append('attempt_id', attemptId);
                navigator.sendBeacon(actionUrl, data);
            });

            displayTime();
            const timer = window.setInterval(() => {
                remainingSeconds -= 1;
                displayTime();
                if (remainingSeconds <= 0) {
                    window.clearInterval(timer);
                    submitQuiz();
                }
            }, 1000);
        })();
    </script>
<?php endif; ?>

<?php require_once '../includes/footer.php'; ?>