export interface Page<T> {
  data: T[];
  current_page: number;
  last_page: number;
}
export interface SchoolAssignmentRequest {
  id: number;
  teacher_id: number;
  offering_id: number | null;
  display_name: string;
  module_name: string | null;
  classroom_name: string | null;
  requested_group_code: string | null;
  requested_module_code: string | null;
  status: string;
  reason: string;
}
export interface SchoolReport {
  id: number;
  reason: string;
  response: string | null;
  status: string;
}
export interface SchoolDeadline {
  kind: string;
  id: number;
  title: string;
  due_at: string;
  url: string;
}
export interface Group {
  id: number;
  name: string;
  official_code: string;
  school_year: string;
  academic_year_id: number;
  status: string;
  filiere?: string | null;
  level?: string | null;
  is_read_only?: boolean;
  module_only_access?: boolean;
  join_enabled: boolean;
  delegate_notices_enabled?: boolean;
  coordinator_id: number | null;
  coordinator_can_manage_roster: boolean;
}
export interface Offering {
  id: number;
  classroom_id: number;
  module_id: number;
  module_name: string;
  module_code: string;
  status: string;
}
export interface Person {
  id: number;
  display_name: string;
  role?: string;
}
export interface SchoolOverview {
  groups: Group[];
  offerings: Offering[];
  available_offerings?: {
    id: number;
    module_name: string;
    classroom_name: string;
    school_year: string;
  }[];
  years: {
    id: number;
    name: string;
    status?: string;
    starts_on?: string;
    ends_on?: string;
  }[];
  modules: {
    id: number;
    name: string;
    code: string;
    status?: string;
  }[];
  teachers: {
    id: number;
    offering_id: number;
    teacher_id: number;
    display_name: string;
  }[];
  delegates: {
    id: number;
    classroom_id: number;
    student_id: number;
    display_name: string;
    ends_at: string;
  }[];
}
export interface RosterRow {
  student_id: number;
  display_name: string;
  school_identifier?: string;
}
export interface Assessment {
  id: number;
  offering_id: number;
  title: string;
  type: string;
  assessed_on: string;
  maximum_score: number | string;
  coefficient: number | string;
  state: string;
  version: number;
  roster_version: number;
  draft_revision_id: number | null;
  published_revision_id: number | null;
}
export type GradeStatus = "graded" | "ungraded" | "absent" | "exempt" | "makeup";
export interface GradeEntry {
  student_id: number;
  display_name: string;
  score: string | number | null;
  status: GradeStatus;
  feedback: string | null;
}
export interface GradeEditorData {
  assessment: Assessment;
  entries: GradeEntry[];
  current_roster_version: number;
  read_only: boolean;
  history: {
    sequence: number;
    reason: string | null;
    published_at: string | null;
  }[];
}
export interface ImportPreview {
  batch_id: string;
  errors: {
    row: number;
    reason: string;
  }[];
  summary: {
    changed: number;
    unchanged: number;
    missing: number;
  };
}
export interface PersonalGrade {
  id: number;
  classroom_id: number;
  classroom_name: string;
  offering_id: number;
  title: string;
  module_name: string;
  school_year: string;
  assessed_on: string;
  score: number | string | null;
  maximum_score: number | string;
  coefficient: number | string;
  status: GradeStatus;
  feedback: string | null;
  sequence: number;
}
export interface SchoolThread {
  id: number;
  classroom_id: number | null;
  subject: string;
  kind: string;
  status: string;
  updated_at: string;
  created_by: number;
  last_read_message_id?: number;
  latest_message_id?: number;
}
export interface SchoolMessage {
  id: number;
  author_id: number;
  display_name: string;
  body: string;
  created_at: string;
}
export interface ThreadView {
  thread: SchoolThread;
  messages: SchoolMessage[];
  participants: {
    id: number;
    display_name: string;
    eligibility: string;
  }[];
}
