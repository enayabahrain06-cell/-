<?php

use App\Http\Controllers\Api\Engagement\ChallengeController;
use App\Http\Controllers\Api\Engagement\CompetitionController;
use App\Http\Controllers\Api\Engagement\EngagementReportController;
use App\Http\Controllers\Api\Engagement\HonorController;
use Illuminate\Support\Facades\Route;

// Honor board, competitions and challenges (sections 13-14), inside auth:sanctum
Route::get('honor/board', [HonorController::class, 'board']);
Route::get('honor/periods', [HonorController::class, 'periods']);
Route::post('honor/compute', [HonorController::class, 'compute']);
Route::post('honor/periods/{period}/honor', [HonorController::class, 'honor']);
Route::post('honor/periods/{period}/publish', [HonorController::class, 'publish']);
Route::get('honor/badges', [HonorController::class, 'badges']);
Route::put('honor/badges/{badge}', [HonorController::class, 'updateBadge']);
Route::get('my/honor', [HonorController::class, 'me']);

Route::get('competitions', [CompetitionController::class, 'index']);
Route::get('competitions/defaults', [CompetitionController::class, 'defaults']);
Route::post('competitions', [CompetitionController::class, 'store']);
Route::get('competitions/{competition}', [CompetitionController::class, 'show'])->whereNumber('competition');
Route::put('competitions/{competition}', [CompetitionController::class, 'update'])->whereNumber('competition');
Route::delete('competitions/{competition}', [CompetitionController::class, 'destroy'])->whereNumber('competition');
Route::post('competitions/{competition}/status', [CompetitionController::class, 'status']);
Route::get('competitions/{competition}/participants', [CompetitionController::class, 'participants']);
Route::get('competitions/{competition}/candidates', [CompetitionController::class, 'candidates']);
Route::post('competitions/{competition}/participants', [CompetitionController::class, 'register']);
Route::delete('competitions/{competition}/participants/{participant}', [CompetitionController::class, 'withdraw']);
Route::get('competitions/{competition}/judge-candidates', [CompetitionController::class, 'judgeCandidates']);
Route::post('competitions/{competition}/judges', [CompetitionController::class, 'addJudge']);
Route::delete('competitions/{competition}/judges/{judge}', [CompetitionController::class, 'removeJudge']);
Route::get('competitions/{competition}/rounds/{round}/sheet', [CompetitionController::class, 'sheet']);
Route::post('competitions/{competition}/rounds/{round}/scores', [CompetitionController::class, 'score']);
Route::get('competitions/{competition}/standings', [CompetitionController::class, 'standings']);
Route::post('competitions/{competition}/publish', [CompetitionController::class, 'publish']);
Route::get('my/competitions', [CompetitionController::class, 'mine']);
Route::post('my/competitions/{competition}/register', [CompetitionController::class, 'registerSelf']);

Route::get('challenges', [ChallengeController::class, 'index']);
Route::post('challenges', [ChallengeController::class, 'store']);
Route::get('challenges/{challenge}', [ChallengeController::class, 'show']);
Route::put('challenges/{challenge}', [ChallengeController::class, 'update']);
Route::delete('challenges/{challenge}', [ChallengeController::class, 'destroy']);
Route::post('challenges/{challenge}/participants', [ChallengeController::class, 'enroll']);
Route::post('challenges/{challenge}/refresh', [ChallengeController::class, 'refresh']);
Route::get('my/challenges', [ChallengeController::class, 'mine']);
Route::post('my/challenges/{challenge}/join', [ChallengeController::class, 'join']);
Route::get('reports/engagement', [EngagementReportController::class, 'show']);
