<script setup>
import { computed, onMounted, reactive, ref } from 'vue';

const csrf = document.querySelector('meta[name="csrf-token"]').content;
const user = ref(null);
const today = ref('');
const loading = ref(true);
const message = ref('');
const errors = ref({});
const view = ref('dashboard');

const loginForm = reactive({ email: '', password: '', remember: false });
const memberForm = reactive({ id: null, name: '', email: '', password: '', role: 'member', active: true });
const teamForm = reactive({ id: null, name: '', manager_id: '', member_ids: [] });
const taskForm = reactive({ id: null, project_name: '', title: '', notes: '', status: 'planned' });
const passwordForm = reactive({ current_password: '', password: '', password_confirmation: '' });
const backlogForm = reactive({ project_name: '', title: '', description: '', team_id: '' });
const filters = reactive({ date: '', member_id: '', status: '' });
const managerFilters = reactive({ date: '', status: '' });
const historyFilters = reactive({ date: '' });

const users = ref([]);
const members = ref([]);
const managers = ref([]);
const teams = ref([]);
const dashboard = ref([]);
const managerDashboard = ref([]);
const tasks = ref([]);
const taskHistory = ref([]);
const backlogs = ref([]);
const backlogTeams = ref([]);

const statusOptions = [
    { value: 'planned', label: 'Planned' },
    { value: 'in_progress', label: 'In progress' },
    { value: 'done', label: 'Done' },
    { value: 'blocked', label: 'Blocked' },
];

const isAdmin = computed(() => user.value?.role === 'admin');
const isMember = computed(() => user.value?.role === 'member');
const isTeamManager = computed(() => user.value?.role === 'team-manager');
const activeMembers = computed(() => members.value.filter((member) => member.active));
const activeManagers = computed(() => managers.value.filter((manager) => manager.active));

async function request(url, options = {}) {
    errors.value = {};
    message.value = '';

    const response = await fetch(url, {
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrf,
            ...(options.headers || {}),
        },
        credentials: 'same-origin',
        ...options,
        body: options.body ? JSON.stringify(options.body) : undefined,
    });

    if (response.status === 204) {
        return null;
    }

    const data = await response.json().catch(() => ({}));

    if (!response.ok) {
        errors.value = data.errors || { general: [data.message || 'Something went wrong.'] };
        throw new Error(data.message || 'Request failed.');
    }

    return data;
}

async function loadSession() {
    try {
        const data = await request('/session');
        user.value = data.user;
        today.value = data.today;
        filters.date = filters.date || data.today;
        managerFilters.date = managerFilters.date || data.today;
        view.value = defaultView();
        await loadCurrentView();
    } catch {
        user.value = null;
    } finally {
        loading.value = false;
    }
}

async function login() {
    const data = await request('/login', {
        method: 'POST',
        body: loginForm,
    });

    user.value = data.user;
    today.value = data.today;
    filters.date = data.today;
    managerFilters.date = data.today;
    view.value = defaultView();
    loginForm.password = '';
    await loadCurrentView();
}

async function logout() {
    await request('/logout', { method: 'POST' });
    user.value = null;
    users.value = [];
    members.value = [];
    managers.value = [];
    teams.value = [];
    dashboard.value = [];
    managerDashboard.value = [];
    tasks.value = [];
    taskHistory.value = [];
    backlogs.value = [];
    backlogTeams.value = [];
}

function defaultView() {
    if (isAdmin.value) return 'dashboard';
    if (isTeamManager.value) return 'manager-dashboard';
    return 'tasks';
}

async function loadCurrentView() {
    if (isAdmin.value) {
        await Promise.all([loadMembers(), loadTeams(), loadDashboard()]);
    }

    if (isTeamManager.value) {
        await loadManagerDashboard();
    }

    if (isMember.value) {
        await loadTasks();
    }
}

async function loadMembers() {
    const data = await request('/admin/members');
    users.value = data.users || data.members;
    members.value = data.members;
    managers.value = data.managers || [];
}

async function saveMember() {
    const url = memberForm.id ? `/admin/members/${memberForm.id}` : '/admin/members';
    const method = memberForm.id ? 'PUT' : 'POST';
    const payload = { ...memberForm };

    if (memberForm.id && !payload.password) {
        delete payload.password;
    }

    await request(url, { method, body: payload });
    resetMemberForm();
    message.value = 'User saved.';
    await Promise.all([loadMembers(), loadTeams(), loadDashboard()]);
}

function editMember(member) {
    memberForm.id = member.id;
    memberForm.name = member.name;
    memberForm.email = member.email;
    memberForm.password = '';
    memberForm.role = member.role || 'member';
    memberForm.active = member.active;
    view.value = 'members';
}

function resetMemberForm() {
    memberForm.id = null;
    memberForm.name = '';
    memberForm.email = '';
    memberForm.password = '';
    memberForm.role = 'member';
    memberForm.active = true;
}

async function loadTeams() {
    const data = await request('/admin/teams');
    teams.value = data.teams;
}

async function saveTeam() {
    const url = teamForm.id ? `/admin/teams/${teamForm.id}` : '/admin/teams';
    const method = teamForm.id ? 'PUT' : 'POST';

    await request(url, {
        method,
        body: {
            name: teamForm.name,
            manager_id: teamForm.manager_id,
            member_ids: teamForm.member_ids,
        },
    });

    resetTeamForm();
    message.value = 'Team saved.';
    await loadTeams();
}

function editTeam(team) {
    teamForm.id = team.id;
    teamForm.name = team.name;
    teamForm.manager_id = team.manager_id || team.manager?.id || '';
    teamForm.member_ids = team.members.map((member) => member.id);
    view.value = 'teams';
}

function resetTeamForm() {
    teamForm.id = null;
    teamForm.name = '';
    teamForm.manager_id = '';
    teamForm.member_ids = [];
}

async function loadDashboard() {
    const params = new URLSearchParams();
    if (filters.date) params.set('date', filters.date);
    if (filters.member_id) params.set('member_id', filters.member_id);
    if (filters.status) params.set('status', filters.status);

    const data = await request(`/admin/dashboard?${params.toString()}`);
    dashboard.value = data.members;
}

async function loadManagerDashboard() {
    const params = new URLSearchParams();
    if (managerFilters.date) params.set('date', managerFilters.date);
    if (managerFilters.status) params.set('status', managerFilters.status);

    const data = await request(`/manager/dashboard?${params.toString()}`);
    managerDashboard.value = data.teams;
}

async function loadTasks() {
    const data = await request('/tasks');
    tasks.value = data.tasks;
    today.value = data.today;
}

async function loadTaskHistory() {
    const params = new URLSearchParams();
    if (historyFilters.date) params.set('date', historyFilters.date);

    const data = await request(`/tasks/history?${params.toString()}`);
    taskHistory.value = data.tasks;
    today.value = data.today;
}

async function clearHistoryFilters() {
    historyFilters.date = '';
    await loadTaskHistory();
}

async function saveTask() {
    const url = taskForm.id ? `/tasks/${taskForm.id}` : '/tasks';
    const method = taskForm.id ? 'PUT' : 'POST';

    await request(url, { method, body: taskForm });
    resetTaskForm();
    message.value = 'Task saved.';
    await loadTasks();
}

function editTask(task) {
    taskForm.id = task.id;
    taskForm.project_name = task.project_name || '';
    taskForm.title = task.title;
    taskForm.notes = task.notes || '';
    taskForm.status = task.status;
}

async function deleteTask(task) {
    await request(`/tasks/${task.id}`, { method: 'DELETE' });
    await loadTasks();
}

async function updatePassword() {
    await request('/password', {
        method: 'PUT',
        body: passwordForm,
    });

    resetPasswordForm();
    message.value = 'Password updated.';
}

function resetTaskForm() {
    taskForm.id = null;
    taskForm.project_name = '';
    taskForm.title = '';
    taskForm.notes = '';
    taskForm.status = 'planned';
}

function resetPasswordForm() {
    passwordForm.current_password = '';
    passwordForm.password = '';
    passwordForm.password_confirmation = '';
}

async function showBacklogView() {
    view.value = 'backlog';
    await loadBacklog();
}

async function loadBacklog() {
    const data = await request('/backlog');
    backlogs.value = data.backlogs;
    backlogTeams.value = data.teams;
}

async function saveBacklog() {
    await request('/backlog', {
        method: 'POST',
        body: backlogForm,
    });
    resetBacklogForm();
    message.value = 'Task added to backlog.';
    await loadBacklog();
}

function resetBacklogForm() {
    backlogForm.project_name = '';
    backlogForm.title = '';
    backlogForm.description = '';
    backlogForm.team_id = '';
}

async function deleteBacklog(backlog) {
    if (confirm('Are you sure you want to delete this backlog task?')) {
        await request(`/backlog/${backlog.id}`, { method: 'DELETE' });
        message.value = 'Backlog task deleted.';
        await loadBacklog();
    }
}

async function moveBacklogToToday(backlog) {
    await request(`/backlog/${backlog.id}/move`, { method: 'POST' });
    message.value = 'Backlog task moved to today.';
    await loadBacklog();
    await loadTasks();
}

async function moveTaskToBacklog(task) {
    if (confirm('Are you sure you want to move this task back to the backlog?')) {
        await request(`/tasks/${task.id}/backlog`, { method: 'POST' });
        message.value = 'Task returned to backlog.';
        await loadTasks();
        if (view.value === 'backlog') {
            await loadBacklog();
        }
    }
}

function firstError(field) {
    return errors.value[field]?.[0] || '';
}

function statusLabel(status) {
    return statusOptions.find((item) => item.value === status)?.label || status;
}

function roleLabel(role) {
    if (role === 'team-manager') return 'Team manager';
    if (role === 'admin') return 'Admin';
    return 'Member';
}

const reportFilters = reactive({
    start_date: '',
    end_date: '',
    team_id: '',
    member_id: '',
});
const reportData = ref(null);
const reportMetadata = ref({ teams: [], members: [] });
const reportLoading = ref(false);

async function loadReportMetadata() {
    const data = await request('/reports/filters');
    reportMetadata.value = {
        teams: data.teams || [],
        members: data.members || [],
    };
}

async function previewReport() {
    const params = new URLSearchParams();
    if (reportFilters.start_date) params.set('start_date', reportFilters.start_date);
    if (reportFilters.end_date) params.set('end_date', reportFilters.end_date);
    if (reportFilters.team_id) params.set('team_id', reportFilters.team_id);
    if (reportFilters.member_id) params.set('member_id', reportFilters.member_id);

    reportLoading.value = true;
    try {
        const data = await request(`/reports/preview?${params.toString()}`);
        reportData.value = data;
    } catch (e) {
        console.error(e);
    } finally {
        reportLoading.value = false;
    }
}

function exportReport() {
    const params = new URLSearchParams();
    if (reportFilters.start_date) params.set('start_date', reportFilters.start_date);
    if (reportFilters.end_date) params.set('end_date', reportFilters.end_date);
    if (reportFilters.team_id) params.set('team_id', reportFilters.team_id);
    if (reportFilters.member_id) params.set('member_id', reportFilters.member_id);

    window.location.href = `/reports/export?${params.toString()}`;
}

async function showReportsView() {
    view.value = 'reports';
    if (!reportFilters.start_date || !reportFilters.end_date) {
        const todayStr = today.value || new Date().toISOString().split('T')[0];
        const d = new Date(todayStr);
        d.setDate(1);
        reportFilters.start_date = d.toISOString().split('T')[0];
        reportFilters.end_date = todayStr;
    }
    reportLoading.value = true;
    try {
        await loadReportMetadata();
        await previewReport();
    } catch (e) {
        console.error(e);
    } finally {
        reportLoading.value = false;
    }
}

onMounted(loadSession);
</script>

<template>
    <main class="min-h-screen bg-slate-50 text-slate-950">
        <section v-if="loading" class="flex min-h-screen items-center justify-center px-4">
            <div class="text-sm font-medium text-slate-600">Loading...</div>
        </section>

        <section v-else-if="!user" class="mx-auto flex min-h-screen w-full max-w-md items-center px-4">
            <form class="w-full rounded-lg border border-slate-200 bg-white p-6 shadow-sm" @submit.prevent="login">
                <div class="mb-6">
                    <h1 class="text-2xl font-semibold">Team Status</h1>
                    <p class="mt-1 text-sm text-slate-600">Sign in to update or review today's work.</p>
                </div>

                <label class="field">
                    <span>Email</span>
                    <input v-model="loginForm.email" type="email" autocomplete="email" required>
                    <small v-if="firstError('email')">{{ firstError('email') }}</small>
                </label>

                <label class="field">
                    <span>Password</span>
                    <input v-model="loginForm.password" type="password" autocomplete="current-password" required>
                    <small v-if="firstError('password')">{{ firstError('password') }}</small>
                </label>

                <label class="mt-2 flex items-center gap-2 text-sm text-slate-700">
                    <input v-model="loginForm.remember" type="checkbox" class="size-4 rounded border-slate-300">
                    Remember me
                </label>

                <button class="primary mt-6 w-full" type="submit">Sign in</button>
                <p v-if="firstError('general')" class="mt-3 text-sm text-red-600">{{ firstError('general') }}</p>
            </form>
        </section>

        <section v-else>
            <header class="border-b border-slate-200 bg-white">
                <div class="mx-auto flex max-w-7xl flex-col gap-4 px-4 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 class="text-xl font-semibold">Team Status</h1>
                        <p class="text-sm text-slate-600">{{ user.name }} · {{ user.role }}</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button v-if="isAdmin" :class="['tab', view === 'dashboard' && 'active']" @click="view = 'dashboard'; loadDashboard()">Dashboard</button>
                        <button v-if="isAdmin" :class="['tab', view === 'members' && 'active']" @click="view = 'members'">Members</button>
                        <button v-if="isAdmin" :class="['tab', view === 'teams' && 'active']" @click="view = 'teams'; loadTeams()">Teams</button>
                        <button v-if="isTeamManager" :class="['tab', view === 'manager-dashboard' && 'active']" @click="view = 'manager-dashboard'; loadManagerDashboard()">Team Status</button>
                        <button v-if="isMember" :class="['tab', view === 'tasks' && 'active']" @click="view = 'tasks'; loadTasks()">Today</button>
                        <button v-if="isMember" :class="['tab', view === 'history' && 'active']" @click="view = 'history'; loadTaskHistory()">History</button>
                        <button :class="['tab', view === 'backlog' && 'active']" @click="showBacklogView()">Backlog</button>
                        <button v-if="isAdmin || isTeamManager" :class="['tab', view === 'reports' && 'active']" @click="showReportsView()">Reports</button>
                        <button :class="['tab', view === 'account' && 'active']" @click="view = 'account'">Account</button>
                        <button class="secondary" @click="logout">Logout</button>
                    </div>
                </div>
            </header>

            <div class="mx-auto max-w-7xl px-4 py-6">
                <p v-if="message" class="mb-4 rounded border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800">{{ message }}</p>

                <section v-if="isAdmin && view === 'dashboard'" class="space-y-5">
                    <div class="flex flex-col gap-3 border-b border-slate-200 pb-4 lg:flex-row lg:items-end">
                        <label class="field compact">
                            <span>Date</span>
                            <input v-model="filters.date" type="date" @change="loadDashboard">
                        </label>
                        <label class="field compact">
                            <span>Member</span>
                            <select v-model="filters.member_id" @change="loadDashboard">
                                <option value="">All members</option>
                                <option v-for="member in members" :key="member.id" :value="member.id">{{ member.name }}</option>
                            </select>
                        </label>
                        <label class="field compact">
                            <span>Status</span>
                            <select v-model="filters.status" @change="loadDashboard">
                                <option value="">All statuses</option>
                                <option v-for="status in statusOptions" :key="status.value" :value="status.value">{{ status.label }}</option>
                            </select>
                        </label>
                    </div>

                    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        <article v-for="member in dashboard" :key="member.id" class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                            <div class="mb-3 flex items-start justify-between gap-3">
                                <div>
                                    <h2 class="font-semibold">{{ member.name }}</h2>
                                    <p class="text-sm text-slate-500">{{ member.email }}</p>
                                </div>
                                <span :class="['pill', member.active ? 'ok' : 'muted']">{{ member.active ? 'Active' : 'Inactive' }}</span>
                            </div>

                            <div class="mb-4 grid grid-cols-4 gap-2 text-center text-xs">
                                <div v-for="status in statusOptions" :key="status.value" class="rounded border border-slate-200 px-2 py-2">
                                    <div class="font-semibold">{{ member.counts[status.value] }}</div>
                                    <div class="text-slate-500">{{ status.label }}</div>
                                </div>
                            </div>

                            <div v-if="member.tasks.length" class="space-y-2">
                                <div v-for="task in member.tasks" :key="task.id" :class="['task-row', task.status === 'blocked' && 'blocked']">
                                    <div class="flex items-start justify-between gap-3">
                                        <div>
                                            <p v-if="task.project_name" class="text-xs font-semibold uppercase text-slate-500">{{ task.project_name }}</p>
                                            <strong>{{ task.title }}</strong>
                                        </div>
                                        <span :class="['pill', task.status]">{{ statusLabel(task.status) }}</span>
                                    </div>
                                    <p v-if="task.notes" class="mt-1 text-sm text-slate-600">{{ task.notes }}</p>
                                </div>
                            </div>
                            <p v-else class="rounded border border-dashed border-slate-300 px-3 py-6 text-center text-sm text-slate-500">No tasks for this date.</p>
                        </article>
                    </div>
                </section>

                <section v-if="isTeamManager && view === 'manager-dashboard'" class="space-y-5">
                    <div class="flex flex-col gap-3 border-b border-slate-200 pb-4 lg:flex-row lg:items-end">
                        <label class="field compact">
                            <span>Date</span>
                            <input v-model="managerFilters.date" type="date" @change="loadManagerDashboard">
                        </label>
                        <label class="field compact">
                            <span>Status</span>
                            <select v-model="managerFilters.status" @change="loadManagerDashboard">
                                <option value="">All statuses</option>
                                <option v-for="status in statusOptions" :key="status.value" :value="status.value">{{ status.label }}</option>
                            </select>
                        </label>
                    </div>

                    <div v-for="team in managerDashboard" :key="team.id" class="space-y-3">
                        <div>
                            <h2 class="font-semibold">{{ team.name }}</h2>
                            <p class="text-sm text-slate-600">{{ team.members.length }} members</p>
                        </div>

                        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                            <article v-for="member in team.members" :key="member.id" class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                                <div class="mb-3 flex items-start justify-between gap-3">
                                    <div>
                                        <h3 class="font-semibold">{{ member.name }}</h3>
                                        <p class="text-sm text-slate-500">{{ member.email }}</p>
                                    </div>
                                    <span :class="['pill', member.active ? 'ok' : 'muted']">{{ member.active ? 'Active' : 'Inactive' }}</span>
                                </div>

                                <div class="mb-4 grid grid-cols-4 gap-2 text-center text-xs">
                                    <div v-for="status in statusOptions" :key="status.value" class="rounded border border-slate-200 px-2 py-2">
                                        <div class="font-semibold">{{ member.counts[status.value] }}</div>
                                        <div class="text-slate-500">{{ status.label }}</div>
                                    </div>
                                </div>

                                <div v-if="member.tasks.length" class="space-y-2">
                                    <div v-for="task in member.tasks" :key="task.id" :class="['task-row', task.status === 'blocked' && 'blocked']">
                                        <div class="flex items-start justify-between gap-3">
                                            <div>
                                                <p v-if="task.project_name" class="text-xs font-semibold uppercase text-slate-500">{{ task.project_name }}</p>
                                                <strong>{{ task.title }}</strong>
                                            </div>
                                            <span :class="['pill', task.status]">{{ statusLabel(task.status) }}</span>
                                        </div>
                                        <p v-if="task.notes" class="mt-1 text-sm text-slate-600">{{ task.notes }}</p>
                                    </div>
                                </div>
                                <p v-else class="rounded border border-dashed border-slate-300 px-3 py-6 text-center text-sm text-slate-500">No tasks for this date.</p>
                            </article>
                        </div>
                    </div>

                    <p v-if="!managerDashboard.length" class="rounded-lg border border-dashed border-slate-300 bg-white px-4 py-10 text-center text-sm text-slate-500">No teams assigned.</p>
                </section>

                <section v-if="isAdmin && view === 'members'" class="grid gap-6 lg:grid-cols-[360px_1fr]">
                    <form class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm" @submit.prevent="saveMember">
                        <h2 class="mb-4 font-semibold">{{ memberForm.id ? 'Edit user' : 'Add user' }}</h2>
                        <label class="field">
                            <span>Name</span>
                            <input v-model="memberForm.name" required>
                            <small v-if="firstError('name')">{{ firstError('name') }}</small>
                        </label>
                        <label class="field">
                            <span>Email</span>
                            <input v-model="memberForm.email" type="email" required>
                            <small v-if="firstError('email')">{{ firstError('email') }}</small>
                        </label>
                        <label class="field">
                            <span>Role</span>
                            <select v-model="memberForm.role" required>
                                <option value="member">Member</option>
                                <option value="team-manager">Team manager</option>
                            </select>
                            <small v-if="firstError('role')">{{ firstError('role') }}</small>
                        </label>
                        <label class="field">
                            <span>Password</span>
                            <input v-model="memberForm.password" type="password" :required="!memberForm.id" minlength="8">
                            <small v-if="firstError('password')">{{ firstError('password') }}</small>
                        </label>
                        <label class="mb-4 flex items-center gap-2 text-sm text-slate-700">
                            <input v-model="memberForm.active" type="checkbox" class="size-4 rounded border-slate-300">
                            Active
                        </label>
                        <div class="flex gap-2">
                            <button class="primary" type="submit">Save user</button>
                            <button class="secondary" type="button" @click="resetMemberForm">Clear</button>
                        </div>
                    </form>

                    <div class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                        <table class="w-full text-left text-sm">
                            <thead class="border-b border-slate-200 bg-slate-100 text-xs uppercase text-slate-600">
                                <tr>
                                    <th class="px-4 py-3">Name</th>
                                    <th class="px-4 py-3">Email</th>
                                    <th class="px-4 py-3">Role</th>
                                    <th class="px-4 py-3">Status</th>
                                    <th class="px-4 py-3"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="member in users" :key="member.id" class="border-b border-slate-100">
                                    <td class="px-4 py-3 font-medium">{{ member.name }}</td>
                                    <td class="px-4 py-3 text-slate-600">{{ member.email }}</td>
                                    <td class="px-4 py-3 text-slate-600">{{ roleLabel(member.role) }}</td>
                                    <td class="px-4 py-3">
                                        <span :class="['pill', member.active ? 'ok' : 'muted']">{{ member.active ? 'Active' : 'Inactive' }}</span>
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        <button class="secondary" @click="editMember(member)">Edit</button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section v-if="isAdmin && view === 'teams'" class="grid gap-6 lg:grid-cols-[380px_1fr]">
                    <form class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm" @submit.prevent="saveTeam">
                        <h2 class="mb-4 font-semibold">{{ teamForm.id ? 'Edit team' : 'Add team' }}</h2>
                        <label class="field">
                            <span>Team name</span>
                            <input v-model="teamForm.name" required maxlength="255">
                            <small v-if="firstError('name')">{{ firstError('name') }}</small>
                        </label>
                        <label class="field">
                            <span>Manager</span>
                            <select v-model="teamForm.manager_id" required>
                                <option value="">Select manager</option>
                                <option v-for="manager in activeManagers" :key="manager.id" :value="manager.id">{{ manager.name }}</option>
                            </select>
                            <small v-if="firstError('manager_id')">{{ firstError('manager_id') }}</small>
                        </label>

                        <div class="mb-4">
                            <div class="mb-2 text-sm font-medium text-slate-700">Members</div>
                            <div class="max-h-72 space-y-2 overflow-auto rounded-md border border-slate-200 p-3">
                                <label v-for="member in activeMembers" :key="member.id" class="flex items-center gap-2 text-sm text-slate-700">
                                    <input v-model="teamForm.member_ids" type="checkbox" class="size-4 rounded border-slate-300" :value="member.id">
                                    <span>{{ member.name }}</span>
                                </label>
                                <p v-if="!activeMembers.length" class="py-6 text-center text-sm text-slate-500">No active members available.</p>
                            </div>
                            <small v-if="firstError('member_ids')" class="mt-1 block text-sm text-red-600">{{ firstError('member_ids') }}</small>
                        </div>

                        <div class="flex gap-2">
                            <button class="primary" type="submit">Save team</button>
                            <button class="secondary" type="button" @click="resetTeamForm">Clear</button>
                        </div>
                    </form>

                    <div class="space-y-3">
                        <article v-for="team in teams" :key="team.id" class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div>
                                    <h2 class="font-semibold">{{ team.name }}</h2>
                                    <p class="text-sm text-slate-600">Manager: {{ team.manager?.name || 'Unassigned' }}</p>
                                    <p class="text-sm text-slate-500">{{ team.members.length }} members</p>
                                </div>
                                <button class="secondary" @click="editTeam(team)">Edit</button>
                            </div>
                            <div v-if="team.members.length" class="mt-3 flex flex-wrap gap-2">
                                <span v-for="member in team.members" :key="member.id" class="pill muted">{{ member.name }}</span>
                            </div>
                        </article>
                        <p v-if="!teams.length" class="rounded-lg border border-dashed border-slate-300 bg-white px-4 py-10 text-center text-sm text-slate-500">No teams created yet.</p>
                    </div>
                </section>

                <section v-if="isMember && view === 'tasks'" class="grid gap-6 lg:grid-cols-[380px_1fr]">
                    <form class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm" @submit.prevent="saveTask">
                        <h2 class="font-semibold">{{ taskForm.id ? 'Edit today task' : 'Add today task' }}</h2>
                        <p class="mb-4 text-sm text-slate-600">{{ today }}</p>
                        <label class="field">
                            <span>Project Name</span>
                            <input v-model="taskForm.project_name" required maxlength="255">
                            <small v-if="firstError('project_name')">{{ firstError('project_name') }}</small>
                        </label>
                        <label class="field">
                            <span>Task</span>
                            <input v-model="taskForm.title" required maxlength="255">
                            <small v-if="firstError('title')">{{ firstError('title') }}</small>
                        </label>
                        <label class="field">
                            <span>Status</span>
                            <select v-model="taskForm.status" required>
                                <option v-for="status in statusOptions" :key="status.value" :value="status.value">{{ status.label }}</option>
                            </select>
                            <small v-if="firstError('status')">{{ firstError('status') }}</small>
                        </label>
                        <label class="field">
                            <span>Notes</span>
                            <textarea v-model="taskForm.notes" rows="4" maxlength="2000"></textarea>
                            <small v-if="firstError('notes')">{{ firstError('notes') }}</small>
                        </label>
                        <div class="flex gap-2">
                            <button class="primary" type="submit">Save task</button>
                            <button class="secondary" type="button" @click="resetTaskForm">Clear</button>
                        </div>
                    </form>

                    <div class="space-y-3">
                        <article v-for="task in tasks" :key="task.id" :class="['rounded-lg border bg-white p-4 shadow-sm', task.status === 'blocked' ? 'border-red-200' : 'border-slate-200']">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div>
                                    <p v-if="task.project_name" class="text-xs font-semibold uppercase text-slate-500">{{ task.project_name }}</p>
                                    <h3 class="font-semibold">{{ task.title }}</h3>
                                    <p v-if="task.notes" class="mt-1 text-sm text-slate-600">{{ task.notes }}</p>
                                </div>
                                <span :class="['pill', task.status]">{{ statusLabel(task.status) }}</span>
                            </div>
                            <div class="mt-4 flex gap-2">
                                <button class="secondary cursor-pointer" @click="editTask(task)">Edit</button>
                                <button v-if="task.team_id !== null" class="secondary cursor-pointer border-slate-300 text-slate-700 hover:bg-slate-50" @click="moveTaskToBacklog(task)">
                                    Move to Backlog
                                </button>
                                <button v-else class="danger cursor-pointer" @click="deleteTask(task)">Delete</button>
                            </div>
                        </article>
                        <p v-if="!tasks.length" class="rounded-lg border border-dashed border-slate-300 bg-white px-4 py-10 text-center text-sm text-slate-500">No tasks added for today.</p>
                    </div>
                </section>

                <section v-if="isMember && view === 'history'" class="space-y-3">
                    <div class="flex flex-col gap-3 border-b border-slate-200 pb-4 lg:flex-row lg:items-end lg:justify-between">
                        <div>
                            <h2 class="font-semibold">Work history</h2>
                            <p class="text-sm text-slate-600">Past work is read-only.</p>
                        </div>
                        <div class="flex flex-col gap-2 sm:flex-row sm:items-end">
                            <label class="field compact">
                                <span>Date</span>
                                <input v-model="historyFilters.date" type="date" @change="loadTaskHistory">
                            </label>
                            <button class="secondary" type="button" @click="clearHistoryFilters">Clear</button>
                        </div>
                    </div>

                    <article v-for="task in taskHistory" :key="task.id" :class="['rounded-lg border bg-white p-4 shadow-sm', task.status === 'blocked' ? 'border-red-200' : 'border-slate-200']">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <p class="text-xs font-semibold uppercase text-slate-500">{{ task.work_date }}</p>
                                <p v-if="task.project_name" class="mt-1 text-xs font-semibold uppercase text-slate-500">{{ task.project_name }}</p>
                                <h3 class="mt-1 font-semibold">{{ task.title }}</h3>
                                <p v-if="task.notes" class="mt-1 text-sm text-slate-600">{{ task.notes }}</p>
                            </div>
                            <span :class="['pill', task.status]">{{ statusLabel(task.status) }}</span>
                        </div>
                    </article>
                    <p v-if="!taskHistory.length" class="rounded-lg border border-dashed border-slate-300 bg-white px-4 py-10 text-center text-sm text-slate-500">No work history yet.</p>
                </section>

                <section v-if="(isAdmin || isTeamManager) && view === 'reports'" class="space-y-6">
                    <div class="flex flex-col gap-4 border-b border-slate-200 pb-4 lg:flex-row lg:items-end">
                        <label class="field compact">
                            <span>Start Date</span>
                            <input v-model="reportFilters.start_date" type="date" required @change="previewReport">
                        </label>
                        <label class="field compact">
                            <span>End Date</span>
                            <input v-model="reportFilters.end_date" type="date" required @change="previewReport">
                        </label>
                        <label v-if="isAdmin" class="field compact">
                            <span>Team</span>
                            <select v-model="reportFilters.team_id" @change="previewReport">
                                <option value="">All teams</option>
                                <option v-for="team in reportMetadata.teams" :key="team.id" :value="team.id">{{ team.name }}</option>
                            </select>
                        </label>
                        <label class="field compact">
                            <span>Team Member</span>
                            <select v-model="reportFilters.member_id" @change="previewReport">
                                <option value="">All members</option>
                                <option v-for="member in reportMetadata.members" :key="member.id" :value="member.id">{{ member.name }}</option>
                            </select>
                        </label>
                        <div class="flex gap-2">
                            <button class="primary" :disabled="reportLoading" @click="exportReport">
                                Export PDF
                            </button>
                        </div>
                    </div>

                    <div v-if="reportLoading" class="text-sm text-slate-500 py-4">Loading preview...</div>

                    <div v-else-if="reportData" class="space-y-6">
                        <!-- Summary Cards -->
                        <div class="grid gap-4 grid-cols-2 md:grid-cols-5">
                            <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm text-center">
                                <div class="text-2xl font-bold text-slate-900">{{ reportData.total_tasks }}</div>
                                <div class="text-xs text-slate-500 uppercase tracking-wider font-semibold mt-1">Total Tasks</div>
                            </div>
                            <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 shadow-sm text-center">
                                <div class="text-2xl font-bold text-emerald-950">{{ reportData.status_counts?.done || 0 }}</div>
                                <div class="text-xs text-emerald-700 uppercase tracking-wider font-semibold mt-1">Done</div>
                            </div>
                            <div class="rounded-lg border border-blue-200 bg-blue-50 p-4 shadow-sm text-center">
                                <div class="text-2xl font-bold text-blue-950">{{ reportData.status_counts?.in_progress || 0 }}</div>
                                <div class="text-xs text-blue-700 uppercase tracking-wider font-semibold mt-1">In Progress</div>
                            </div>
                            <div class="rounded-lg border border-red-200 bg-red-50 p-4 shadow-sm text-center">
                                <div class="text-2xl font-bold text-red-950">{{ reportData.status_counts?.blocked || 0 }}</div>
                                <div class="text-xs text-red-700 uppercase tracking-wider font-semibold mt-1">Blocked</div>
                            </div>
                            <div class="rounded-lg border border-slate-200 bg-slate-100 p-4 shadow-sm text-center">
                                <div class="text-2xl font-bold text-slate-800">{{ reportData.status_counts?.planned || 0 }}</div>
                                <div class="text-xs text-slate-600 uppercase tracking-wider font-semibold mt-1">Planned</div>
                            </div>
                        </div>

                        <!-- Members Tasks List -->
                        <div class="space-y-4">
                            <div v-for="member in reportData.members" :key="member.email" class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                                <div class="mb-4 flex flex-col justify-between gap-2 border-b border-slate-100 pb-3 sm:flex-row sm:items-center">
                                    <div>
                                        <h3 class="text-lg font-semibold text-slate-900">{{ member.name }}</h3>
                                        <p class="text-sm text-slate-500">{{ member.email }}</p>
                                    </div>
                                    <div class="flex flex-wrap gap-2 text-xs">
                                        <span class="rounded bg-emerald-100 px-2 py-1 text-emerald-800">Done: {{ member.counts?.done || 0 }}</span>
                                        <span class="rounded bg-blue-100 px-2 py-1 text-blue-800">In Progress: {{ member.counts?.in_progress || 0 }}</span>
                                        <span class="rounded bg-red-100 px-2 py-1 text-red-800">Blocked: {{ member.counts?.blocked || 0 }}</span>
                                        <span class="rounded bg-slate-100 px-2 py-1 text-slate-800">Planned: {{ member.counts?.planned || 0 }}</span>
                                    </div>
                                </div>

                                <div v-if="member.tasks.length" class="overflow-x-auto">
                                    <table class="w-full text-left text-sm">
                                        <thead class="bg-slate-50 text-xs uppercase text-slate-600">
                                            <tr>
                                                <th class="px-4 py-2" style="width: 15%;">Date</th>
                                                <th class="px-4 py-2" style="width: 20%;">Project</th>
                                                <th class="px-4 py-2" style="width: 50%;">Task Details</th>
                                                <th class="px-4 py-2 text-center" style="width: 15%;">Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr v-for="task in member.tasks" :key="task.id" class="border-t border-slate-100">
                                                <td class="px-4 py-3 font-medium text-slate-600">{{ task.work_date }}</td>
                                                <td class="px-4 py-3 font-semibold text-slate-700">{{ task.project_name || 'General' }}</td>
                                                <td class="px-4 py-3">
                                                    <div class="font-medium text-slate-900">{{ task.title }}</div>
                                                    <div v-if="task.notes" class="mt-1 text-xs text-slate-500 whitespace-pre-line">{{ task.notes }}</div>
                                                </td>
                                                <td class="px-4 py-3 text-center">
                                                    <span :class="['pill', task.status]">{{ statusLabel(task.status) }}</span>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                                <p v-else class="rounded border border-dashed border-slate-200 px-3 py-6 text-center text-sm text-slate-500">No tasks logged during the selected period.</p>
                            </div>

                            <p v-if="!reportData.members.length" class="rounded-lg border border-dashed border-slate-300 bg-white px-4 py-10 text-center text-sm text-slate-500">No members match the filtered criteria.</p>
                        </div>
                    </div>
                </section>

                <section v-if="view === 'backlog'" class="space-y-6">
                    <div class="flex flex-col gap-6" :class="[(isAdmin || isTeamManager) ? 'lg:grid lg:grid-cols-[380px_1fr]' : '']">
                        <!-- Add backlog form (Only for Admin/Manager) -->
                        <form v-if="isAdmin || isTeamManager" class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm h-fit space-y-4" @submit.prevent="saveBacklog">
                            <div>
                                <h2 class="font-semibold text-lg text-slate-900">Add backlog task</h2>
                                <p class="text-sm text-slate-500">Create a task for a team's backlog.</p>
                            </div>

                            <label class="field">
                                <span>Project Name</span>
                                <input v-model="backlogForm.project_name" required placeholder="e.g. Website, API, Operations" maxlength="255">
                                <small v-if="firstError('project_name')">{{ firstError('project_name') }}</small>
                            </label>

                            <label class="field">
                                <span>Task Title</span>
                                <input v-model="backlogForm.title" required placeholder="What needs to be done?" maxlength="255">
                                <small v-if="firstError('title')">{{ firstError('title') }}</small>
                            </label>

                            <label class="field">
                                <span>Team</span>
                                <select v-model="backlogForm.team_id" required>
                                    <option value="">Select team</option>
                                    <option v-for="team in backlogTeams" :key="team.id" :value="team.id">
                                        {{ team.name }}
                                    </option>
                                </select>
                                <small v-if="firstError('team_id')">{{ firstError('team_id') }}</small>
                            </label>

                            <label class="field">
                                <span>Description / Notes</span>
                                <textarea v-model="backlogForm.description" rows="4" placeholder="Optional details..." maxlength="2000"></textarea>
                                <small v-if="firstError('description')">{{ firstError('description') }}</small>
                            </label>

                            <div class="flex gap-2 pt-2">
                                <button class="primary flex-1 justify-center cursor-pointer" type="submit">Add to Backlog</button>
                                <button class="secondary cursor-pointer" type="button" @click="resetBacklogForm">Clear</button>
                            </div>
                        </form>

                        <!-- Backlog task list -->
                        <div class="space-y-4 flex-1">
                            <div class="flex items-center justify-between border-b border-slate-200 pb-3">
                                <div>
                                    <h2 class="font-semibold text-lg text-slate-900">Task Backlog</h2>
                                    <p class="text-sm text-slate-500">
                                        {{ isAdmin ? 'All team backlogs' : (isTeamManager ? 'Managed team backlogs' : 'Your team backlogs') }}
                                    </p>
                                </div>
                                <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600">
                                    {{ backlogs.length }} tasks
                                </span>
                            </div>

                            <div v-if="backlogs.length" class="grid gap-4 sm:grid-cols-1 xl:grid-cols-2">
                                <article v-for="task in backlogs" :key="task.id" class="flex flex-col justify-between rounded-lg border border-slate-200 bg-white p-4 shadow-sm hover:border-slate-300 transition-all">
                                    <div class="space-y-2">
                                        <div class="flex flex-wrap items-start justify-between gap-2">
                                            <div class="flex flex-wrap gap-1.5">
                                                <span class="inline-flex items-center rounded bg-blue-50 px-2 py-0.5 text-xs font-semibold uppercase text-blue-700">
                                                    {{ task.project_name }}
                                                </span>
                                                <span class="inline-flex items-center rounded bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-700">
                                                    Team: {{ task.team?.name }}
                                                </span>
                                            </div>
                                        </div>
                                        <h3 class="text-base font-semibold text-slate-900">{{ task.title }}</h3>
                                        <p v-if="task.description" class="text-sm text-slate-600 whitespace-pre-wrap">{{ task.description }}</p>
                                    </div>

                                    <div class="mt-4 flex gap-2 border-t border-slate-100 pt-3">
                                        <!-- Member can move to today's tasks -->
                                        <button v-if="isMember" class="primary py-1.5 px-3 text-xs flex items-center gap-1 cursor-pointer" @click="moveBacklogToToday(task)">
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="size-3.5">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                                            </svg>
                                            Move to Today
                                        </button>
                                        <!-- Admin/Manager can delete -->
                                        <button v-if="isAdmin || isTeamManager" class="danger py-1.5 px-3 text-xs cursor-pointer" @click="deleteBacklog(task)">
                                            Delete
                                        </button>
                                    </div>
                                </article>
                            </div>
                            
                            <div v-else class="rounded-lg border border-dashed border-slate-300 bg-white px-4 py-12 text-center">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="mx-auto size-8 text-slate-400">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.375c.621 0 1.125-.504 1.125-1.125V11.25c0-.621-.504-1.125-1.125-1.125H9.75M8.25 21h8.25c1.243 0 2.25-1.007 2.25-2.25V6.75C18.75 5.507 17.743 4.5 16.5 4.5H7.5C6.257 4.5 5.25 5.507 5.25 6.75v12c0 1.243 1.007 2.25 2.25 2.25z" />
                                </svg>
                                <h3 class="mt-2 text-sm font-semibold text-slate-900">No backlog tasks</h3>
                                <p class="mt-1 text-sm text-slate-500">
                                    {{ (isAdmin || isTeamManager) ? 'Get started by creating a task in the form.' : 'No tasks assigned to your team\'s backlog.' }}
                                </p>
                            </div>
                        </div>
                    </div>
                </section>

                <section v-if="view === 'account'" class="max-w-md">
                    <form class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm" @submit.prevent="updatePassword">
                        <h2 class="mb-4 font-semibold">Update password</h2>
                        <label class="field">
                            <span>Current password</span>
                            <input v-model="passwordForm.current_password" type="password" autocomplete="current-password" required>
                            <small v-if="firstError('current_password')">{{ firstError('current_password') }}</small>
                        </label>
                        <label class="field">
                            <span>New password</span>
                            <input v-model="passwordForm.password" type="password" autocomplete="new-password" minlength="8" required>
                            <small v-if="firstError('password')">{{ firstError('password') }}</small>
                        </label>
                        <label class="field">
                            <span>Confirm new password</span>
                            <input v-model="passwordForm.password_confirmation" type="password" autocomplete="new-password" minlength="8" required>
                            <small v-if="firstError('password_confirmation')">{{ firstError('password_confirmation') }}</small>
                        </label>
                        <div class="flex gap-2">
                            <button class="primary" type="submit">Update password</button>
                            <button class="secondary" type="button" @click="resetPasswordForm">Clear</button>
                        </div>
                    </form>
                </section>
            </div>
        </section>
    </main>
</template>
