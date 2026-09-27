<?php

namespace App\Providers;

use App\Enums\MediaCollection;
use App\Models\Media;
use App\Models\Student;
use App\Models\User;
use App\Policies\StudentPhotoPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class MediaServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $policy = new StudentPhotoPolicy;

        Gate::define('view-photo', fn (User $user, Student $student) => $policy->view($user, $student));
        Gate::define('update-photo', fn (User $user, Student $student) => $policy->update($user, $student));
        Gate::define('bulk-photos', fn (User $user) => $policy->bulk($user));

        // Who may stream a media file, decided by its collection.
        Gate::define('view-media', function (User $user, Media $media) use ($policy) {
            $collection = $media->collection instanceof MediaCollection ? $media->collection : MediaCollection::tryFrom((string) $media->collection);
            $student = self::studentOf($media);
            $own = $student && $policy->isSelfOrGuardian($user, $student);

            return match ($collection) {
                MediaCollection::Photo, MediaCollection::PhotoThumb => $student ? $policy->view($user, $student) : $user->can('students.view'),
                MediaCollection::ReceiptImage, MediaCollection::ReceiptPdf => $own || $user->can('wallets.view'),
                MediaCollection::ExamSheet, MediaCollection::Recitation => $own || $user->can('exams.view') || $user->can('exams.grade')
                    || ($student && $user->hasRole('teacher') && $policy->teaches($user, $student)),
                MediaCollection::Certificate => ($own && $media->model?->status?->value === 'approved') || $user->can('students.view')
                    || ($student && $user->hasRole('teacher') && $policy->teaches($user, $student)),
                MediaCollection::Logo => true,
                default => false,
            };
        });
    }

    /** Resolve the student a media row belongs to (directly, or through a model with student_id). */
    public static function studentOf(Media $media): ?Student
    {
        $model = $media->model;

        if ($model instanceof Student) {
            return $model;
        }

        if ($model && isset($model->student_id)) {
            return Student::find($model->student_id);
        }

        if ($model && method_exists($model, 'attempt') && $model->attempt) { // ExamAnswer → attempt → student
            return Student::find($model->attempt->student_id);
        }

        return null;
    }
}
