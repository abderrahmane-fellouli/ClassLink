import { useState } from 'react'
import { AppShell } from './components/UI'
import type { Screen, Role } from './components/UI'

// Public / auth screens
import { HomeScreen, LoginScreen, AccessDeniedScreen, PrivacyScreen } from './screens/PublicScreens'

// Student screens
import {
  DashboardScreen,
  ClassScreen,
  JoinScreen,
  QuizScreen,
  QuizResultScreen,
  DeadlinesScreen,
  PartnersScreen,
  RequestsScreen,
  ProfileScreen,
} from './screens/StudentScreens'

// Teacher screens
import {
  TeacherDashboard,
  CreateClassScreen,
  ManageClassScreen,
  PublishContentScreen,
  CreateQuizScreen,
  AiQuizScreen,
  GradeAssignmentsScreen,
  ClassProgressScreen,
} from './screens/TeacherScreens'

// Admin screens
import {
  AdminOverviewScreen,
  AdminUsersScreen,
  AdminClassesScreen,
  AdminAiScreen,
  AdminAuditScreen,
} from './screens/AdminScreens'

export default function App() {
  const [screen, setScreen] = useState<Screen>('home')
  const [role, setRole] = useState<Role>('student')

  function navTo(s: Screen) { setScreen(s) }

  function handleLogin(r: Role) {
    setRole(r)
    setScreen(r === 'admin' ? 'admin-overview' : r === 'teacher' ? 'teacher-dashboard' : 'dashboard')
  }

  // Public screens — no shell
  if (screen === 'home')         return <HomeScreen onNav={navTo}/>
  if (screen === 'login')        return <LoginScreen onLogin={handleLogin}/>
  if (screen === 'access-denied')return <AccessDeniedScreen onNav={navTo}/>
  if (screen === 'privacy')      return <PrivacyScreen onNav={navTo}/>

  // Quiz result is inside the student shell but uses the quiz result component
  const isQuizResult = screen === 'quiz-result'

  return (
    <AppShell screen={screen} onNav={navTo} role={role}>
      {/* ── Student ──────────────────────────────────────── */}
      {screen === 'dashboard'   && <DashboardScreen onNav={navTo}/>}
      {screen === 'class'       && <ClassScreen onNav={navTo}/>}
      {screen === 'join'        && <JoinScreen/>}
      {screen === 'quiz'        && <QuizScreen onFinish={() => navTo('quiz-result')}/>}
      {screen === 'quiz-result' && <QuizResultScreen onBack={() => navTo('class')}/>}
      {screen === 'deadlines'   && <DeadlinesScreen/>}
      {screen === 'partners'    && <PartnersScreen/>}

      {/* ── Teacher ──────────────────────────────────────── */}
      {screen === 'teacher-dashboard' && <TeacherDashboard onNav={navTo}/>}
      {screen === 'create-class'      && <CreateClassScreen onNav={navTo}/>}
      {screen === 'manage-class'      && <ManageClassScreen onNav={navTo}/>}
      {screen === 'publish-content'   && <PublishContentScreen onNav={navTo}/>}
      {screen === 'create-quiz'       && <CreateQuizScreen onNav={navTo}/>}
      {screen === 'ai-quiz'           && <AiQuizScreen onNav={navTo}/>}
      {screen === 'grade-assignments' && <GradeAssignmentsScreen onNav={navTo}/>}
      {screen === 'class-progress'    && <ClassProgressScreen onNav={navTo}/>}
      {screen === 'requests'          && <RequestsScreen/>}

      {/* ── Admin ────────────────────────────────────────── */}
      {screen === 'admin-overview' && <AdminOverviewScreen onNav={navTo}/>}
      {screen === 'admin-users'    && <AdminUsersScreen onNav={navTo}/>}
      {screen === 'admin-classes'  && <AdminClassesScreen onNav={navTo}/>}
      {screen === 'admin-ai'       && <AdminAiScreen onNav={navTo}/>}
      {screen === 'admin-audit'    && <AdminAuditScreen onNav={navTo}/>}

      {/* ── Shared ───────────────────────────────────────── */}
      {screen === 'profile' && <ProfileScreen role={role}/>}
    </AppShell>
  )
}
