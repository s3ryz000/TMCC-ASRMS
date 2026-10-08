import React from 'react';
import { configure, render, screen, waitFor, within } from '@testing-library/react';

// The portal layouts render after their first requests resolve; on a busy CI
// machine that can take over a second, so lookups wait up to 5 s.
configure({ asyncUtilTimeout: 5000 });
jest.setTimeout(15000);
import userEvent from '@testing-library/user-event';
import { createMemoryRouter, RouterProvider } from 'react-router-dom';
import { routes } from '../routes/Router';
import { apiClient } from '../lib/api/client';
import { queryClient } from '../lib/react-query/queryClient';
import { localDateString } from '../lib/tools';

/**
 * Student portal screens (#91, #90, #93, #92, #88, #89) and the registrar's
 * appointment calendar (#83), on the real routes with the API mocked. The
 * data is made up; the shapes follow the API.
 */
jest.mock('../lib/api/client', () => ({
  apiClient: { get: jest.fn(), post: jest.fn(), put: jest.fn(), patch: jest.fn(), delete: jest.fn() },
}));

// jsdom has no ResizeObserver (the toast library, sileo, measures toasts with
// it) and no scrollIntoView (the SIS page scrolls to the form); browsers do.
beforeAll(() => {
  global.ResizeObserver = global.ResizeObserver || class { observe() {} unobserve() {} disconnect() {} };
  if (!Element.prototype.scrollIntoView) Element.prototype.scrollIntoView = () => {};
});

const USERS = {
  student: { id: 30, name: 'Tess Tester', username: '269901', role: 'student', roles: [{ name: 'student' }] },
  staff: { id: 1, name: 'Rita Registrar', username: 'uat.staff', role: 'staff', roles: [{ name: 'staff' }] },
};

const roadmapRow = (code, status, extra = {}) => ({
  curriculum_year_level: 1, curriculum_semester: '1', subject_code: code, subject_description: `${code} title`,
  units: 3, prerequisites: '', archived: false, grade: '', status, remarks: '', academic_year: '', semester: '', ...extra,
});

const ACADEMIC = {
  student: { student_number: '269901', name: 'Tess Tester', program: 'BS Tourism Management', program_code: 'BSTM', enrollment_date: '2026-08-10' },
  summary: { overall_gwa: 1.5, latin_honors: { eligible: false, reason: '', honor: null }, terms: [], years: [] },
  curriculum: {
    roadmap: [roadmapRow('TPC4', 'Archived', { archived: true }), roadmapRow('TPC5', 'Eligible to Take')],
    total_curriculum_units: 6, completed_units: 0, units_left: 6, failed_subjects_count: 0,
  },
  notifications: [],
  residency: { maximum_residency_years: 5, elapsed_academic_years: 1, used_regular_terms: 1, remaining_required_subjects: [] },
};

let responses;
const signIn = (who) => {
  sessionStorage.setItem('auth_token', 'test-token');
  sessionStorage.setItem('auth_user', JSON.stringify(USERS[who]));
};
const renderAt = (path) => {
  const router = createMemoryRouter(routes, { initialEntries: [path] });
  render(<RouterProvider router={router} />);
  return router;
};

beforeEach(() => {
  responses = {
    '/student/profile': { student: { student_id: 3, first_name: 'Tess', last_name: 'Tester', student_number: '269901' }, academic_year: '2026-2027', semester: '1st Semester' },
    '/student/academic-summary': ACADEMIC,
    '/dashboard': { kpis: { pending_requests: 1, approved: 2, rejected: 3, released: 4 }, my_requests: [] },
    '/student/record-requests': { data: [] },
    '/student/notifications': { data: [], unread_count: 0, total: 0 },
    '/student/profile-updates': { data: [] },
  };
  apiClient.get.mockImplementation((url) => Promise.resolve({ data: responses[url] ?? {} }));
  apiClient.post.mockResolvedValue({ data: { message: 'ok', record_request: { id: 9 } } });
});

afterEach(() => {
  sessionStorage.clear();
  queryClient.clear();
  jest.clearAllMocks();
});

// ------------------------------------------------------------------ #91

test('#91 the dashboard shows the request summary and no false residency warning', async () => {
  signIn('student');
  renderAt('/dashboard');

  const section = (await screen.findByRole('heading', { name: /request/i, level: 3 })).closest('section');
  await waitFor(() => expect(within(section).getByText('4')).toBeInTheDocument());
  for (const [label, count] of [['Pending', '1'], ['Approved', '2'], ['Rejected', '3'], ['Released', '4']]) {
    const card = within(section).getByText(label).parentElement;
    expect(card).toHaveTextContent(count);
  }
  expect(document.body.textContent).not.toMatch(/Maximum Residency Period Reached/i);
});

// ------------------------------------------------------------------ #90

test('#90 approved requests show the appointment and the slip; rejected show the reason; released keep Download PDF', async () => {
  responses['/student/record-requests'] = {
    data: [
      { id: 11, record_type: 'transcript', academic_year: null, semester: null, status: 'approved', appointment_at: '2026-10-12T01:00:00.000000Z', requested_at: '2026-10-08T01:00:00Z' },
      { id: 12, record_type: 'certificate_of_grades', academic_year: null, semester: null, status: 'rejected', rejection_reason: 'Unpaid clearance', requested_at: '2026-10-08T01:00:00Z' },
      { id: 13, record_type: 'copy_of_grades', academic_year: null, semester: null, status: 'released', requested_at: '2026-10-08T01:00:00Z' },
    ],
  };
  signIn('student');
  renderAt('/dashboard/request');

  expect((await screen.findAllByText(/Claim on/))[0]).toHaveTextContent(/Oct(ober)? 12, 2026/);
  expect(screen.getAllByRole('button', { name: /Download approval slip/ }).length).toBeGreaterThan(0);
  expect(screen.getAllByText(/Reason: Unpaid clearance/).length).toBeGreaterThan(0);
  expect(screen.getAllByRole('button', { name: /Download PDF/ }).length).toBeGreaterThan(0);
});

// ------------------------------------------------------------------ #93

test('#93 Request Document opens a form with purpose and copies before anything is sent', async () => {
  signIn('student');
  renderAt('/dashboard/request');

  const [requestButton] = await screen.findAllByRole('button', { name: /Request Document/ });
  await userEvent.click(requestButton);

  const purpose = await screen.findByLabelText(/Purpose/);
  const copies = screen.getByLabelText('Number of copies');
  expect(copies).toHaveValue(1);
  expect(apiClient.post).not.toHaveBeenCalled();

  await userEvent.type(purpose, 'Employment');
  await userEvent.clear(copies);
  await userEvent.type(copies, '2');
  await userEvent.click(screen.getByRole('button', { name: 'Submit request' }));

  await waitFor(() => expect(apiClient.post).toHaveBeenCalledWith('/student/record-requests', expect.objectContaining({ purpose: 'Employment', copies: 2 })));
  // The form closes once the request is created.
  await waitFor(() => expect(screen.queryByLabelText(/Purpose/)).not.toBeInTheDocument());
});

// ------------------------------------------------------------------ #83

test('#83 the approval calendar disables days that have passed', async () => {
  responses['/staff/pending-requests'] = {
    data: [{ id: 21, record_type: 'transcript', status: 'pending', purpose: 'Employment', copies: 1, requested_at: '2026-10-08T01:00:00Z', student: { student_number: '269901', first_name: 'Tess', last_name: 'Tester' } }],
  };
  responses['/staff/appointment-slots'] = { taken: [], slots: [] };
  signIn('staff');
  renderAt('/staff/requests');

  await userEvent.click(await screen.findByRole('button', { name: /^\s*Approve\s*$/ }));
  await screen.findByText('Approve Request & Set Schedule');

  const today = new Date();
  const days = screen.getAllByRole('button').filter((b) => /^\d{1,2}$/.test(b.textContent));
  const thisMonth = days.filter((b) => !b.className.includes('opacity-35'));
  const past = thisMonth.filter((b) => Number(b.textContent) < today.getDate());
  past.forEach((b) => {
    expect(b).toBeDisabled();
    expect(b).toHaveAttribute('title', 'This day has passed');
  });
  const todayButton = thisMonth.find((b) => Number(b.textContent) === today.getDate());
  if (today.getDay() !== 0) expect(todayButton).toBeEnabled();
  expect(localDateString()).toMatch(/^\d{4}-\d{2}-\d{2}$/);
});

// ------------------------------------------------------------------ #92

test('#92 Academic Records marks an archived subject and does not offer it as eligible', async () => {
  signIn('student');
  renderAt('/dashboard/academic-records');

  const tags = await screen.findAllByText('Archived');
  expect(tags.length).toBeGreaterThan(0);
  const archivedRow = screen.getAllByText('TPC4')[0].closest('tr, li, div');
  expect(archivedRow).not.toHaveTextContent('Eligible to Take');
  expect(screen.getAllByText('TPC5').length).toBeGreaterThan(0);
});

// ------------------------------------------------------------------ #88

test('#88 the SIS page lists update requests with remarks and offers Correct and resubmit', async () => {
  responses['/student/profile'] = {
    student: { student_id: 3, first_name: 'Tess', last_name: 'Tester', student_number: '269901', address: 'Old Street' },
  };
  responses['/student/profile-updates'] = {
    data: [{
      id: 5, status: 'revision_required', reason: 'Attach a clearer certificate.',
      fields: [{ field: 'address', label: 'address', old: 'Old Street', new: 'New Street' }],
      submitted_at: '2026-10-08 09:00:00', decided_at: '2026-10-08 10:00:00', history: [], has_supporting_document: true,
    }],
  };
  signIn('student');
  renderAt('/dashboard/sis');

  expect(await screen.findByRole('heading', { name: 'My Update Requests' })).toBeInTheDocument();
  expect(screen.getByText('Revision Required')).toBeInTheDocument();
  expect(screen.getByText(/Attach a clearer certificate\./)).toBeInTheDocument();

  await userEvent.click(screen.getByRole('button', { name: 'Correct and resubmit' }));
  expect(await screen.findByText('Correcting your returned request')).toBeInTheDocument();
  expect(screen.getByDisplayValue('New Street')).toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'Resubmit' })).toBeInTheDocument();
});

test('#88 the registrar lists update requests by status, Pending first', async () => {
  responses['/staff/pending-profile-updates'] = [];
  signIn('staff');
  renderAt('/staff/profile-updates');

  const tabs = await screen.findByRole('tablist', { name: 'Filter by status' });
  const names = within(tabs).getAllByRole('tab').map((t) => t.textContent);
  expect(names).toEqual(['Pending', 'Revision Required', 'Approved', 'Rejected']);
  expect(within(tabs).getByRole('tab', { name: 'Pending' })).toHaveAttribute('aria-selected', 'true');
  await waitFor(() => expect(apiClient.get).toHaveBeenCalledWith('/staff/pending-profile-updates', { params: { status: 'pending' } }));

  await userEvent.click(within(tabs).getByRole('tab', { name: 'Revision Required' }));
  await waitFor(() => expect(apiClient.get).toHaveBeenCalledWith('/staff/pending-profile-updates', { params: { status: 'revision_required' } }));
});

// ------------------------------------------------------------------ #89

test('#89 the bell shows the unread count; opening a notice marks it read and goes to its page', async () => {
  responses['/student/notifications'] = {
    data: [{ id: 'n1', kind: 'profile_update', status: 'revision_required', message: 'Your profile update (address) was returned for revision.', link: '/dashboard/sis', read_at: null, created_at: '2026-10-08 10:00:00' }],
    unread_count: 3, total: 3,
  };
  apiClient.patch.mockResolvedValue({ data: { unread_count: 2 } });
  signIn('student');
  const router = renderAt('/dashboard');

  const bell = await screen.findByRole('button', { name: 'Notifications, 3 unread' });
  expect(bell).toHaveTextContent('3');

  await userEvent.click(bell);
  await userEvent.click(await screen.findByText(/was returned for revision/));
  await waitFor(() => expect(apiClient.patch).toHaveBeenCalledWith('/student/notifications/n1/read'));
  await waitFor(() => expect(router.state.location.pathname).toBe('/dashboard/sis'));
});
