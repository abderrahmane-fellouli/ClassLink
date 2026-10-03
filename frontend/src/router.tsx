import { createBrowserRouter, Navigate, useNavigate, useParams } from 'react-router-dom'
import { GuestRoute, ProtectedRoute, AccessDeniedScreen } from './components/guards'
import { AppShell } from './components/AppShell'
import { useAuth } from './context/AuthContext'
import { useI18n } from './i18n'
import { Btn } from './components/UI'
import {
  HomeScreen,
  LoginScreen,
  PrivacyScreen,
  MicrosoftCallbackScreen,
  PendingScreen,
} from './screens/PublicScreens'
import {
  StudentDashboard,
  MyClassesScreen,
  StudentClassScreen,
  QuizAttemptScreen,
  AssignmentDetailScreen,
  FlashcardStudyScreen,
  DeadlinesScreen,
  PartnersScreen,
  JoinClassScreen,
} from './screens/StudentScreens'
import { ProfileScreen } from './screens/ProfileScreen'
import {
  TeacherDashboard,
  TeacherRequestsScreen,
  ClassProgressScreen,
  CreateClassScreen,
} from './screens/TeacherScreens'
import {
  TeacherClassScreen,
  QuizEditorScreen,
  QuizResultsScreen,
  GradeScreen,
} from './screens/TeacherClassScreens'
import {
  AdminDashboard,
  AdminUsersScreen,
  AdminClassesScreen,
  AdminAiScreen,
  AdminAuditScreen,
} from './screens/AdminScreens'

export const router = createBrowserRouter([
  { path: '/', element: <HomeScreen/> },
  { path: '/login', element: <GuestRoute><LoginScreen/></GuestRoute> },
  { path: '/auth/microsoft/callback', element: <MicrosoftCallbackScreen/> },
  { path: '/privacy', element: <PrivacyScreen/> },
  { path: '/pending', element: <PendingScreen/> },
  { path: '/denied', element: <AccessDeniedScreen/> },
  {
    path: '/app',
    element: (
      <ProtectedRoute roles={['student', 'teacher', 'admin']}>
        <AppShell>
          <RoleHome/>
        </AppShell>
      </ProtectedRoute>
    ),
  },
  {
    path: '/app/dashboard',
    element: <Navigate to="/app" replace/>,
  },
  {
    path: '/app/profile',
    element: (
      <ProtectedRoute roles={['student', 'teacher', 'admin']}>
        <AppShell>
          <ProfileScreen/>
        </AppShell>
      </ProtectedRoute>
    ),
  },
  /* Étudiant */
  {
    path: '/app/classes',
    element: (
      <ProtectedRoute roles={['student', 'teacher']}>
        <AppShell>
          <RoleClasses/>
        </AppShell>
      </ProtectedRoute>
    ),
  },
  {
    path: '/app/classes/:id',
    element: (
      <ProtectedRoute roles={['student']}>
        <AppShell>
          <StudentClassScreen/>
        </AppShell>
      </ProtectedRoute>
    ),
  },
  {
    path: '/app/classes/:classroomId/quizzes/:id',
    element: (
      <ProtectedRoute roles={['student']}>
        <AppShell>
          <QuizAttemptScreen/>
        </AppShell>
      </ProtectedRoute>
    ),
  },
  {
    // F-DEV-02 : depot d'un rendu et lecture de la note.
    path: '/app/assignments/:assignmentId',
    element: (
      <ProtectedRoute roles={['student']}>
        <AppShell>
          <AssignmentDetailScreen/>
        </AppShell>
      </ProtectedRoute>
    ),
  },
  {
    // F-QUI-08 : revision par flashcards.
    path: '/app/flashcard-decks/:deckId',
    element: (
      <ProtectedRoute roles={['student', 'teacher', 'admin']}>
        <AppShell>
          <FlashcardStudyScreen/>
        </AppShell>
      </ProtectedRoute>
    ),
  },
  {
    path: '/app/deadlines',
    element: (
      <ProtectedRoute roles={['student']}>
        <AppShell>
          <DeadlinesScreen/>
        </AppShell>
      </ProtectedRoute>
    ),
  },
  {
    path: '/app/partners',
    element: (
      <ProtectedRoute roles={['student']}>
        <AppShell>
          <PartnersScreen/>
        </AppShell>
      </ProtectedRoute>
    ),
  },
  {
    path: '/app/join',
    element: (
      <ProtectedRoute roles={['student']}>
        <AppShell>
          <JoinClassScreen/>
        </AppShell>
      </ProtectedRoute>
    ),
  },
  /* Enseignant */
  {
    path: '/app/requests',
    element: (
      <ProtectedRoute roles={['teacher']}>
        <AppShell>
          <TeacherRequestsScreen/>
        </AppShell>
      </ProtectedRoute>
    ),
  },
  {
    path: '/app/progression',
    element: (
      <ProtectedRoute roles={['teacher']}>
        <AppShell>
          <ClassProgressScreen/>
        </AppShell>
      </ProtectedRoute>
    ),
  },
  {
    path: '/app/classes/new',
    element: (
      <ProtectedRoute roles={['teacher']}>
        <AppShell>
          <CreateClassScreen/>
        </AppShell>
      </ProtectedRoute>
    ),
  },
  {
    path: '/app/classes/:id/manage',
    element: (
      <ProtectedRoute roles={['teacher']}>
        <AppShell>
          <TeacherClassScreen/>
        </AppShell>
      </ProtectedRoute>
    ),
  },
  {
    path: '/app/classes/:id/manage/quizzes/new',
    element: (
      <ProtectedRoute roles={['teacher']}>
        <AppShell>
          <QuizEditorScreen/>
        </AppShell>
      </ProtectedRoute>
    ),
  },
  {
    path: '/app/classes/:id/manage/quizzes/:quizId/edit',
    element: <ProtectedRoute roles={['teacher']}><AppShell><QuizEditorScreen/></AppShell></ProtectedRoute>,
  },
  {
    path: '/app/classes/:id/manage/quizzes/:quizId/results',
    element: (
      <ProtectedRoute roles={['teacher']}>
        <AppShell>
          <QuizResultsScreen/>
        </AppShell>
      </ProtectedRoute>
    ),
  },
  {
    path: '/app/classes/:id/manage/assignments/:assignmentId/grade',
    element: (
      <ProtectedRoute roles={['teacher']}>
        <AppShell>
          <GradeScreen/>
        </AppShell>
      </ProtectedRoute>
    ),
  },
  {
    // Ancien lien du prototype : redirigé vers la gestion de classe.
    path: '/app/teacher/classes/:id',
    element: <TeacherClassRedirect/>,
  },
  /* Admin */
  {
    path: '/app/admin',
    element: (
      <ProtectedRoute roles={['admin']}>
        <AppShell>
          <AdminDashboard/>
        </AppShell>
      </ProtectedRoute>
    ),
  },
  {
    path: '/app/admin/users',
    element: (
      <ProtectedRoute roles={['admin']}>
        <AppShell>
          <AdminUsersScreen/>
        </AppShell>
      </ProtectedRoute>
    ),
  },
  {
    path: '/app/admin/classes',
    element: (
      <ProtectedRoute roles={['admin']}>
        <AppShell>
          <AdminClassesScreen/>
        </AppShell>
      </ProtectedRoute>
    ),
  },
  {
    path: '/app/admin/ai',
    element: (
      <ProtectedRoute roles={['admin']}>
        <AppShell>
          <AdminAiScreen/>
        </AppShell>
      </ProtectedRoute>
    ),
  },
  {
    path: '/app/admin/audit',
    element: (
      <ProtectedRoute roles={['admin']}>
        <AppShell>
          <AdminAuditScreen/>
        </AppShell>
      </ProtectedRoute>
    ),
  },
  {
    // Filet de securite : aucune URL ne doit aboutir a une page vide.
    path: '*',
    element: (
      <NotFoundScreen/>
    ),
  },
])

function NotFoundScreen() {
  const { t } = useI18n()
  const navigate = useNavigate()
  const { user } = useAuth()

  return (
    <div className="min-h-screen flex flex-col items-center justify-center gap-4 text-center px-6">
      <p className="font-display text-5xl font-semibold text-[var(--primary)]">404</p>
      <p className="text-[var(--muted-foreground)]">{t('notFound.body')}</p>
      <div className="flex gap-2">
        <Btn variant="secondary" onClick={() => navigate(-1)}>{t('common.back')}</Btn>
        <Btn onClick={() => navigate(user ? '/app' : '/')}>{t('notFound.home')}</Btn>
      </div>
    </div>
  )
}

function RoleHome() {
  const { role } = useAuth()
  if (role === 'teacher') return <TeacherDashboard/>
  if (role === 'admin') return <AdminDashboard/>
  return <StudentDashboard/>
}

function RoleClasses() {
  const { role } = useAuth()
  return role === 'teacher' ? <TeacherDashboard/> : <MyClassesScreen/>
}

function TeacherClassRedirect() {
  const { id } = useParams()
  return <Navigate to={`/app/classes/${id}/manage`} replace/>
}
