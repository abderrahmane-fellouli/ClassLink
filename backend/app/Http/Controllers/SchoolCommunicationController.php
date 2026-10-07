<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\SchoolCommunicationService;
use App\Services\SchoolNoticeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SchoolCommunicationController extends Controller
{
    public function __construct(private SchoolCommunicationService $messages) {}

    public function index(Request $r)
    {
        $page = $this->messages->visibleThreads($r->user())->paginate(30);

        return response()->json($page)->header('Cache-Control', 'private, no-store');
    }

    public function create(Request $r)
    {
        $data = $r->validate(['classroom_id' => ['required', 'integer'], 'kind' => ['required', 'in:private_question,delegate_contact,representation,organization'],
            'subject' => ['required', 'string', 'max:191'], 'body' => ['required', 'string', 'max:5000'], 'participant_ids' => ['required', 'array', 'min:1', 'max:7'],
            'participant_ids.*' => ['required', 'integer', 'distinct'], 'assessment_id' => ['nullable', 'integer'], 'offering_id' => ['nullable', 'integer'], 'attachments' => ['prohibited']]);

        return response()->json(['id' => $this->messages->create($r->user(), $data)], 201);
    }

    public function show(Request $r, int $thread)
    {
        $record = $this->messages->thread($r->user(), $thread);
        $data = $r->validate(['before' => ['sometimes', 'integer', 'min:1']]);
        $query = DB::table('school_messages as m')->join('users as u', 'u.id', '=', 'm.author_id')->where('m.thread_id', $thread)->select('m.id', 'm.author_id', 'u.display_name', 'm.body', 'm.created_at');
        if (isset($data['before'])) {
            $query->where('m.id', '<', $data['before']);
        }
        $messages = $query->orderByDesc('m.id')->limit(50)->get();
        $seen = (int) ($messages->max('id') ?? 0);
        DB::table('school_thread_participants')->where('thread_id', $thread)->where('user_id', $r->user()->id)->where('last_read_message_id', '<', $seen)->update(['last_read_message_id' => $seen]);

        return response()->json(['thread' => $record, 'messages' => $messages,
            'participants' => DB::table('school_thread_participants as p')->join('users as u', 'u.id', '=', 'p.user_id')->where('p.thread_id', $thread)->select('u.id', 'u.display_name', 'p.eligibility')->get()])->header('Cache-Control', 'private, no-store');
    }

    public function send(Request $r, int $thread)
    {
        $data = $r->validate(['body' => ['required', 'string', 'max:5000'], 'attachments' => ['prohibited']]);

        return response()->json(['id' => $this->messages->message($r->user(), $thread, $data['body'])], 201);
    }

    public function resolve(Request $r, int $thread)
    {
        $this->messages->thread($r->user(), $thread, true);
        DB::table('school_threads')->where('id', $thread)->update(['status' => 'resolved', 'updated_at' => now()]);
        AuditLog::record($r->user(), 'school.thread.resolve', ['thread_id' => $thread]);

        return response()->noContent();
    }

    public function previewNotice(Request $r, SchoolNoticeService $notices)
    {
        $data = $r->validate(['audience' => ['required', 'in:classes,delegates,all'], 'group_ids' => ['required_unless:audience,all', 'array', 'min:1', 'max:100'], 'group_ids.*' => ['integer', 'distinct']]);

        return response()->json($notices->preview($r->user(), $data));
    }

    public function publishNotice(Request $r, SchoolNoticeService $notices)
    {
        $data = $r->validate(['preview_id' => ['required', 'uuid'], 'subject' => ['required', 'string', 'max:191'], 'body' => ['required', 'string', 'max:5000']]);

        return response()->json(['thread_ids' => $notices->publish($r->user(), $data['preview_id'], $data['subject'], $data['body'])], 201);
    }

    public function supportContacts(Request $r)
    {
        return response()->json(['data' => User::where('role', 'admin')->where('is_active', true)->select('id', 'display_name')->orderBy('id')->limit(50)->get()]);
    }

    public function report(Request $r, int $thread)
    {
        $this->messages->thread($r->user(), $thread);
        $data = $r->validate(['assigned_to' => ['required', 'integer', 'exists:users,id'], 'reason' => ['required', 'string', 'max:2000']]);
        $recipient = User::findOrFail($data['assigned_to']);
        abort_unless($recipient->isAdmin() && $recipient->is_active, 422);
        $id = DB::table('school_reports')->insertGetId($data + ['thread_id' => $thread, 'reported_by' => $r->user()->id, 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
        AuditLog::record($r->user(), 'school.report.create', ['report_id' => $id, 'assigned_to' => $recipient->id]);

        return response()->json(['id' => $id], 201);
    }

    public function reports(Request $r)
    {
        // No message/history join: a report never grants hidden conversation access.
        return response()->json(DB::table('school_reports')->where(fn ($q) => $q->where('reported_by', $r->user()->id)->orWhere('assigned_to', $r->user()->id))
            ->orderByDesc('id')->paginate(30))->header('Cache-Control', 'private, no-store');
    }

    public function respondReport(Request $r, int $report)
    {
        abort_unless($r->user()->isAdmin(), 403);
        $data = $r->validate(['response' => ['required', 'string', 'max:2000'], 'status' => ['required', 'in:open,resolved']]);
        $record = DB::table('school_reports')->find($report);
        abort_unless($record && $record->assigned_to === $r->user()->id, 404);
        DB::table('school_reports')->where('id', $report)->update($data + ['updated_at' => now()]);
        AuditLog::record($r->user(), 'school.report.respond', ['report_id' => $report, 'status' => $data['status']]);

        return response()->noContent();
    }
}
