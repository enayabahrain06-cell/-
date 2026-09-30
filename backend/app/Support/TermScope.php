<?php

namespace App\Support;

use App\Models\AcademicTerm;
use App\Models\Package;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Academic-term scoping for staff lists. The staff shell sends ?term_id=<id> (the selected term, the current one
 * by default) or ?term_id=all. Without the parameter nothing is filtered, so older callers and the public pages
 * see what they always saw.
 *
 * A term is either an academic_terms id (int) or, for the dashboard's older ?term= parameter, a free-text
 * packages.term label (string). Everything term-bound reaches it through its package.
 */
final class TermScope
{
    /** The requested term: int id, legacy string label, or null for "all terms". */
    public static function fromRequest(Request $request): int|string|null
    {
        $id = $request->query('term_id');
        if (is_string($id) && ctype_digit($id)) {
            return (int) $id;
        }
        if (is_string($id) && $id !== '' && $id !== 'all') {
            abort(422, __('terms.errors.invalid'));
        }
        $label = $request->query('term');

        return is_string($label) && $label !== '' && ($id === null || $id === '') ? $label : null;
    }

    /** Filter a query on its own academic_term_id / term columns (packages, invoices). */
    public static function packages(Builder $query, int|string|null $term): Builder
    {
        return match (true) {
            $term === null => $query,
            is_int($term) => $query->where($query->qualifyColumn('academic_term_id'), $term),
            default => $query->where($query->qualifyColumn('term'), $term),
        };
    }

    /** Filter a query through a relation that leads to a package (e.g. "package", "lesson.package"). */
    public static function via(Builder $query, int|string|null $term, string $relation = 'package'): Builder
    {
        return $term === null ? $query : $query->whereHas($relation, fn (Builder $q) => self::packages($q, $term));
    }

    /**
     * Records that may follow a package directly or through their circle (exams). Rows linked to neither are kept
     * when their date falls inside the term's dates (or when the term has no dates), so nothing silently disappears.
     */
    public static function viaPackageOrLesson(Builder $query, int|string|null $term, string $dateColumn): Builder
    {
        if ($term === null) {
            return $query;
        }
        $dated = is_int($term) ? AcademicTerm::find($term) : null;

        return $query->where(fn (Builder $w) => $w
            ->whereHas('package', fn (Builder $p) => self::packages($p, $term))
            ->orWhereHas('lesson.package', fn (Builder $p) => self::packages($p, $term))
            ->orWhere(fn (Builder $n) => $n->whereNull($n->qualifyColumn('package_id'))->whereNull($n->qualifyColumn('lesson_id'))
                ->when(! is_int($term), fn ($q) => $q->whereRaw('1 = 0'))
                ->when($dated?->start_date, fn ($q, $d) => $q->whereDate($q->qualifyColumn($dateColumn), '>=', $d->toDateString()))
                ->when($dated?->end_date, fn ($q, $d) => $q->whereDate($q->qualifyColumn($dateColumn), '<=', $d->toDateString()))));
    }

    public static function matches(?Package $package, int|string|null $term): bool
    {
        return match (true) {
            $term === null => true,
            $package === null => false,
            is_int($term) => (int) $package->academic_term_id === $term,
            default => $package->term === $term,
        };
    }

    /** Term for a new package or invoice when none was chosen: the current one. */
    public static function defaultId(): ?int
    {
        return AcademicTerm::current()?->id;
    }
}
