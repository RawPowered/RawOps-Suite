/**
 * Module Quiz Handler
 * Shared quiz submission and scoring logic for training modules
 */

async function submitModuleQuiz(moduleId) {
    const questions = document.querySelectorAll('.quiz-question');
    
    let totalQuestions = 0;
    let correctAnswers = 0;
    let allAnswered = true;
    
    questions.forEach(q => {
        const answer = q.querySelector('.answer');
        const correctValue = answer ? answer.getAttribute('data-answer') : null;
        const selected = q.querySelector('input[type="radio"]:checked');
        
        if (correctValue) {
            totalQuestions++;
            if (!selected) {
                allAnswered = false;
            } else if (selected.value.toLowerCase() === correctValue.toLowerCase()) {
                correctAnswers++;
            }
        }
    });
    
    if (!allAnswered) {
        alert('Please answer all questions before submitting.');
        return;
    }
    
    const percent = totalQuestions > 0 ? Math.round((correctAnswers / totalQuestions) * 100) : 0;
    const passed = percent >= 80;
    
    // Save to database
    try {
        const basePath = window.path_with_base ? window.path_with_base('/api/training-quiz-submit.php') : '/api/training-quiz-submit.php';
        await fetch(basePath, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams({
                module_id: moduleId,
                score_percent: percent,
                correct_answers: correctAnswers,
                total_questions: totalQuestions,
                passed: passed ? '1' : '0'
            })
        });
    } catch (e) {
        console.error('Failed to save quiz result:', e);
    }
    
    showQuizResults({ passed, score_percent: percent, correct_answers: correctAnswers, total_questions: totalQuestions });
}

function showQuizResults(data) {
    const resultsDiv = document.getElementById('quiz-results');
    if (!resultsDiv) return;
    
    const passed = data.passed;
    const percent = data.score_percent;
    
    // Reveal all answers
    document.querySelectorAll('.answer').forEach(answer => {
        answer.style.display = 'block';
    });
    
    // Show results
    resultsDiv.innerHTML = `
        <div style="background: ${passed ? 'linear-gradient(135deg, #dcfce7, #bbf7d0)' : 'linear-gradient(135deg, #fef3c7, #fed7aa)'}; 
                    padding: 40px; border-radius: 12px; margin: 32px 0; text-align: center; 
                    border: 3px solid ${passed ? '#16a34a' : '#f59e0b'};">
            <div style="font-size: 72px; margin-bottom: 16px;">${passed ? '🎉' : '📚'}</div>
            <h2 style="color: ${passed ? '#166534' : '#92400e'}; margin: 0 0 16px 0;">
                ${passed ? 'Congratulations! You Passed!' : 'Keep Studying!'}
            </h2>
            <div style="font-size: 48px; font-weight: bold; color: ${passed ? '#059669' : '#d97706'}; margin-bottom: 16px;">
                ${percent}%
            </div>
            <p style="color: ${passed ? '#166534' : '#92400e'}; font-size: 16px; margin-bottom: 24px;">
                ${data.correct_answers} out of ${data.total_questions} questions correct
                ${passed ? '' : ' - You need 80% to pass'}
            </p>
            ${passed ? 
                '<p style="color: #166534; font-weight: 600;">✅ You can now complete this module and continue to the next one!</p>' :
                '<p style="color: #92400e; margin-bottom: 16px;">Review the correct answers below and try again when ready.</p>' +
                '<button onclick="location.reload()" class="btn" style="background: #f59e0b; color: white; padding: 12px 32px; margin-top: 8px; border: none; border-radius: 8px; cursor: pointer;">Retry Quiz</button>'
            }
        </div>
    `;
    
    resultsDiv.style.display = 'block';
    resultsDiv.scrollIntoView({ behavior: 'smooth', block: 'center' });
    
    // Hide submit button
    const submitSection = document.getElementById('quiz-submit-section');
    if (submitSection) submitSection.style.display = 'none';
}



