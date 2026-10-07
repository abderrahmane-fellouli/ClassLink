<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\GradeSpreadsheet;
use App\Services\OfficialGradeService;
use App\Services\SchoolAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OfficialGradeController extends Controller
{
    public function __construct(private OfficialGradeService $grades, private SchoolAccess $access, private GradeSpreadsheet $sheets) {}

    public function index(Request $r, int $offering)
    {
        $this->access->offering($r->user(), $offering);
        abort_unless($this->access->teaches($r->user(), $offering), 403);

        return response()->json(DB::table('official_assessments')->where('offering_id', $offering)->orderByDesc('assessed_on')->paginate(50));
    }

    public function create(Request $r, int $offering)
    {
        $data = $r->validate(['title' => ['required', 'string', 'max:255'], 'type' => ['required', 'in:exam,continuous,project,assignment'], 'assessed_on' => ['required', 'date'],
            'maximum_score' => ['required', 'numeric', 'gt:0', 'max:10000', 'decimal:0,2'], 'coefficient' => ['required', 'numeric', 'gt:0', 'max:1000', 'decimal:0,2']]);

        return response()->json(['id' => $this->grades->create($r->user(), $offering, $data)], 201);
    }

    public function show(Request $r, int $assessment)
    {
        $a = $this->grades->assessment($r->user(), $assessment);
        $revision = $a->draft_revision_id ?: $a->published_revision_id;
        $entries = DB::table('assessment_candidates as c')->leftJoin('grade_entries as g', fn ($join) => $join->on('g.student_id', '=', 'c.student_id')->where('g.revision_id', $revision))
            ->where('c.assessment_id', $assessment)->select('c.student_id', 'c.display_name', 'g.score', 'g.status', 'g.feedback')->orderBy('c.student_id')->get();

        $offering = DB::table('module_offerings')->find($a->offering_id);

        return response()->json(['assessment' => $a, 'entries' => $entries,
            'read_only' => $this->access->readOnly($this->access->group($offering->classroom_id)),
            'current_roster_version' => DB::table('classrooms')->where('id', $offering->classroom_id)->value('roster_version'),
            'history' => DB::table('grade_revisions')->where('assessment_id', $assessment)->orderByDesc('sequence')->get()])->header('Cache-Control', 'private, no-store');
    }

    public function save(Request $r, int $assessment)
    {
        $data = $r->validate(['version' => ['required', 'integer', 'min:1'], 'rows' => ['required', 'array', 'min:1', 'max:5000'], 'rows.*.student_id' => ['required', 'integer'],
            'rows.*.status' => ['required', 'string'], 'rows.*.score' => ['nullable', 'regex:/^\d{1,5}([.,]\d{1,2})?$/D'], 'rows.*.feedback' => ['nullable', 'string', 'max:2000']]);

        return response()->json(['version' => $this->grades->save($r->user(), $assessment, $data['version'], $data['rows'])]);
    }

    public function template(Request $r, int $assessment)
    {
        $a = $this->grades->assessment($r->user(), $assessment);
        $format = $r->validate(['format' => ['sometimes', 'in:csv,xlsx']])['format'] ?? 'xlsx';
        $instructions = __('api.school.template_instructions', ['max' => $a->maximum_score]);
        $content = $this->sheets->export($this->sheets->matrix($a), $format, $instructions);

        return response($content)->header('Content-Type', $format === 'xlsx' ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'text/csv; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="assessment-'.$assessment.'.'.$format.'"')->header('Cache-Control', 'private, no-store');
    }

    public function preview(Request $r, int $assessment)
    {
        $a = $this->grades->assessment($r->user(), $assessment, true);
        $r->validate(['file' => ['required', 'file', 'max:2048', 'extensions:csv,txt,xlsx']]);
        $file = $r->file('file');
        [$rows, $errors] = $this->sheets->parse($file->getRealPath(), strtolower($file->getClientOriginalExtension()), $a);

        return response()->json($this->grades->preview($r->user(), $assessment, $rows, $errors))->header('Cache-Control', 'private, no-store');
    }

    public function commit(Request $r, int $assessment)
    {
        $data = $r->validate(['batch_id' => ['required', 'uuid']]);

        return response()->json(['version' => $this->grades->commitImport($r->user(), $assessment, $data['batch_id'])]);
    }

    public function publish(Request $r, int $assessment)
    {
        $data = $r->validate(['version' => ['required', 'integer', 'min:1'], 'summary' => ['required', 'string', 'min:3', 'max:1000']]);

        return response()->json(['version' => $this->grades->publish($r->user(), $assessment, $data['version'], $data['summary'])]);
    }

    public function correction(Request $r, int $assessment)
    {
        $data = $r->validate(['version' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'min:3', 'max:1000']]);

        return response()->json(['version' => $this->grades->correction($r->user(), $assessment, $data['version'], $data['reason'])]);
    }

    public function results(Request $r)
    {
        $query = $this->grades->results($r->user());
        $data = $r->validate(['year' => ['sometimes', 'string', 'max:32'], 'module_id' => ['sometimes', 'integer']]);
        if (isset($data['year'])) {
            $query->where('c.school_year', $data['year']);
        }
        if (isset($data['module_id'])) {
            $query->where('m.id', $data['module_id']);
        }

        return response()->json($query->paginate(50))->header('Cache-Control', 'private, no-store');
    }

    public function reconcile(Request $r, int $assessment)
    {
        $data = $r->validate(['version' => ['required', 'integer', 'min:1'], 'add_student_ids' => ['present', 'array', 'max:5000'], 'add_student_ids.*' => ['integer', 'distinct']]);

        return response()->json(['version' => $this->grades->reconcileRoster($r->user(), $assessment, $data['version'], $data['add_student_ids'])]);
    }

    public function exportOwn(Request $r)
    {
        $query = $this->grades->results($r->user());
        $data = $r->validate(['year' => ['sometimes', 'string', 'max:32']]);
        if (isset($data['year'])) {
            $query->where('c.school_year', $data['year']);
        }
        $records = $query->limit(1001)->get();
        abort_if($records->count() > 1000, 422);
        $matrix = [['assessment_id', 'title', 'module', 'year', 'score', 'maximum', 'coefficient', 'status', 'feedback']];
        foreach ($records as $row) {
            $matrix[] = [$row->id, $row->title, $row->module_name, $row->school_year, $row->score, $row->maximum_score, $row->coefficient, $row->status, $row->feedback];
        }

        return response($this->sheets->export($matrix, 'csv', ''))->header('Content-Type', 'text/csv; charset=UTF-8')->header('Content-Disposition', 'attachment; filename="my-private-results.csv"')->header('Cache-Control', 'private, no-store');
    }

    public function ownResult(Request $r, int $assessment)
    {
        $result = $this->grades->results($r->user())->where('a.id', $assessment)->first();
        abort_unless($result, 404);

        return response()->json($result)->header('Cache-Control', 'private, no-store');
    }

    public function ownContacts(Request $r, int $assessment)
    {
        $result = $this->grades->results($r->user())->where('a.id', $assessment)->first();
        abort_unless($result, 404);
        $ids = DB::table('teaching_assignments')->where('offering_id', $result->offering_id)->whereNotNull('active_teacher_id')->whereNull('ends_at')->pluck('teacher_id');

        return response()->json(['data' => User::whereIn('id', $ids)->where('role', 'teacher')->where('is_active', true)->select('id', 'display_name', 'role')->get()]);
    }
}
