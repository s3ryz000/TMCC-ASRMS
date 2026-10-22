import React from 'react';
import { configure, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createMemoryRouter, RouterProvider } from 'react-router-dom';
import { routes } from '../routes/Router';
import { apiClient } from '../lib/api/client';
import { queryClient } from '../lib/react-query/queryClient';

// The portal layouts render after their first requests resolve; on a busy CI
// machine that can take over a second, so lookups wait up to 5 s.
configure({ asyncUtilTimeout: 5000 });
jest.setTimeout(15000);

/**
 * Grade double approval (#107): the approvals page (approve, return) and the
 * pending / returned banners on grade entry, on the real routes with the API
 * client mocked. The data is made up; the shapes follow the API.
 */
jest.mock('../lib/api/client', () => ({
  apiClient: { get: jest.fn(), post: jest.fn(), put: jest.fn(), patch: jest.fn(), delete: jest.fn() },
}));

// jsdom has no ResizeObserver (the toast library, sileo, measures toasts with it); browsers do.
beforeAll(() => {
  global.ResizeObserver = global.ResizeObserver || class { observe() {} unobserve() {} disconnect() {} };
});

const USERS = {
  approver: { id: 5, name: 'Hana Head', username: 'registrar.head', role: 'staff', roles: [{ name: 'staff' }], can_approve_grades: true },
  encoder: { id: 1, name: 'Rita Registrar', username: 'uat.staff', role: 'staff', roles: [{ name: 'staff' }], can_approve_grades: false },
};

const SUBMISSION = {
  id: 7, status: 'pending', step: 'registrar', student_id: 3, student_number: '269901', student_name: 'Tess Tester',
  program: 'BSTM', academic_year: '2026-2027', semester: '1',
  submitted_by: { id: 1, name: 'Rita Registrar' }, submitted_at: '2026-10-22T09:00:00+08:00',
  decided_by: null, decided_at: null, remarks: null, item_count: 1,
};

const DETAIL = {
  ...SUBMISSION,
  items: [{
    id: 70, grade_id: 31, subject_code: 'TM101', subject_title: 'Intro to Tourism', units: 3,
    old: { grade_value: null, status: 'Enrolled', remarks: null },
    new: { grade_value: '1.75', status: 'Passed', remarks: 'Passed' },
    supporting_document_reference: null,
  }],
};

const STUDENT = {
  student_id: 3, student_number: '269901', first_name: 'Tess', last_name: 'Tester', middle_name: null,
  date_of_birth: '2006-01-02', email: 'tess@example.test', contact_number: '09170000000', address: 'Test Street',
  enrollment_date: '2026-08-10', sex: 'F', program_id: 1,
  program: { id: 1, code: 'BSTM', name: 'BS Tourism Management' },
  user: { id: 30, name: 'Tess Tester', username: '269901', email: 'tess@example.test' },
  enrollments: [], grades: [],
};

const progressWith = (term) => ({
  student_id: 3, program: 'BSTM', program_name: 'BS Tourism Management',
  next_allowed_term: { can_add: false, blocking_reason: 'Grades are not complete.' },
  available_subjects: [], max_eligible_units: 0, retake_subjects: [],
  grouped_enrollments: [{
    year_level: 1, semester: '1', academic_year: '2026-2027',
    subjects: [{
      enrollment_id: 41, subject_id: 9, subject_code: 'TM101', subject_title: 'Intro to Tourism', units: 3,
      enrollment_status: 'Enrolled', grade_id: 31, grade_value: null, grade_status: 'Enrolled', remarks: null,
    }],
    pending_submission: null, last_returned: null,
    ...term,
  }],
});

let responses;
const signIn = (who) => {
  sessionStorage.setItem('auth_token', 'test-token');
  sessionStorage.setItem('auth_user', JSON.stringify(USERS[who]));
  responses['/user'] = USERS[who];
};
const renderAt = (path) => {
  const router = createMemoryRouter(routes, { initialEntries: [path] });
  render(<RouterProvider router={router} />);
  return router;
};

beforeEach(() => {
  responses = {
    '/dashboard': { kpis: {}, recent_activity: [] },
    '/staff/grade-submissions': { data: [SUBMISSION] },
    '/staff/grade-submissions/7': { data: DETAIL },
    '/staff/students/3': { student: STUDENT },
    '/staff/students/3/academic-summary': { summary: { terms: [] } },
    '/staff/students/3/academic-progress': progressWith({}),
    '/staff/programs': { programs: [{ id: 1, code: 'BSTM', name: 'BS Tourism Management', archived: false }] },
  };
  apiClient.get.mockImplementation((url) => Promise.resolve({ data: responses[url] ?? {} }));
  apiClient.patch.mockResolvedValue({ data: { message: '1 grade(s) approved.' } });
});

afterEach(() => {
  sessionStorage.clear();
  queryClient.clear();
  jest.clearAllMocks();
});

const openSubmission = async () => {
  await userEvent.click(await screen.findByRole('button', { name: 'Review grades for 269901' }));
  return screen.findByRole('dialog', { name: 'Review grades' });
};

describe('Grade approvals page', () => {
  test('lists pending submissions, shows old → new and approves after confirming', async () => {
    signIn('approver');
    renderAt('/staff/grade-approvals');

    const row = (await screen.findByText('Tess Tester')).closest('tr');
    expect(within(row).getByText('Rita Registrar')).toBeInTheDocument();
    expect(within(row).getByText('A.Y. 2026-2027, 1st semester')).toBeInTheDocument();
    // The sidebar link shows the count.
    expect(screen.getByRole('link', { name: /Grade Approvals/ })).toHaveTextContent('1');

    const dialog = await openSubmission();
    expect(await within(dialog).findByText('TM101')).toBeInTheDocument();
    expect(within(dialog).getByText('Enrolled')).toBeInTheDocument();
    expect(within(dialog).getByText('1.75 (Passed)')).toBeInTheDocument();

    await userEvent.click(within(dialog).getByRole('button', { name: /Approve/ }));
    const confirm = await screen.findByRole('dialog', { name: 'Approve grades' });
    expect(apiClient.patch).not.toHaveBeenCalled();
    await userEvent.click(within(confirm).getByRole('button', { name: 'Approve' }));

    await waitFor(() => expect(apiClient.patch).toHaveBeenCalledWith('/staff/grade-submissions/7/approve'));
  });

  test('returning needs remarks, which are sent to the API', async () => {
    signIn('approver');
    renderAt('/staff/grade-approvals');
    const dialog = await openSubmission();
    await within(dialog).findByText('TM101');

    await userEvent.click(within(dialog).getByRole('button', { name: /Return to encoder/ }));
    expect(await within(dialog).findByText('Say what needs to be corrected.')).toBeInTheDocument();
    expect(apiClient.patch).not.toHaveBeenCalled();

    await userEvent.type(within(dialog).getByLabelText(/Remarks/), 'TM101 should be 2.00.');
    await userEvent.click(within(dialog).getByRole('button', { name: /Return to encoder/ }));

    await waitFor(() => expect(apiClient.patch).toHaveBeenCalledWith('/staff/grade-submissions/7/return', { remarks: 'TM101 should be 2.00.' }));
  });

  test('staff without the permission see no link and no queue', async () => {
    signIn('encoder');
    renderAt('/staff/grade-approvals');

    expect(await screen.findByText("You don't have access to grade approvals.")).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: /Grade Approvals/ })).not.toBeInTheDocument();
    expect(apiClient.get).not.toHaveBeenCalledWith('/staff/grade-submissions', expect.anything());
  });
});

describe('Grade entry while grades wait for approval', () => {
  test('a pending term shows the banner and the proposed value, is locked, and its encoder can cancel', async () => {
    responses['/staff/students/3/academic-progress'] = progressWith({
      pending_submission: {
        id: 7, status: 'pending', submitted_by: { id: 1, name: 'Rita Registrar' }, submitted_at: '2026-10-22T09:00:00+08:00',
        items: [{ grade_id: 31, grade_value: '1.75', status: null, remarks: null }],
      },
    });
    signIn('encoder');
    renderAt('/staff/students/3/edit/grades');

    const banner = await screen.findByRole('status');
    expect(banner).toHaveTextContent('Waiting for approval: submitted by Rita Registrar on');
    expect(screen.getByText('— → pending 1.75')).toBeInTheDocument();
    expect(screen.getByLabelText('Grade for TM101')).toBeDisabled();
    expect(screen.getByRole('button', { name: /Save Grades/ })).toBeDisabled();

    await userEvent.click(screen.getByRole('button', { name: /Cancel submission/ }));
    await waitFor(() => expect(apiClient.patch).toHaveBeenCalledWith('/staff/grade-submissions/7/cancel'));
  });

  test('only the encoder gets the cancel button', async () => {
    responses['/staff/students/3/academic-progress'] = progressWith({
      pending_submission: {
        id: 7, status: 'pending', submitted_by: { id: 99, name: 'Other Staff' }, submitted_at: '2026-10-22T09:00:00+08:00',
        items: [{ grade_id: 31, grade_value: '1.75', status: null, remarks: null }],
      },
    });
    signIn('encoder');
    renderAt('/staff/students/3/edit/grades');

    expect(await screen.findByRole('status')).toHaveTextContent('submitted by Other Staff');
    expect(screen.queryByRole('button', { name: /Cancel submission/ })).not.toBeInTheDocument();
  });

  test('a returned term shows the remarks and can be edited again', async () => {
    responses['/staff/students/3/academic-progress'] = progressWith({
      last_returned: { id: 6, remarks: 'TM101 should be 2.00.', decided_by: 'Hana Head', decided_at: '2026-10-22T10:00:00+08:00' },
    });
    signIn('encoder');
    renderAt('/staff/students/3/edit/grades');

    const banner = await screen.findByRole('alert');
    expect(banner).toHaveTextContent('Returned by Hana Head');
    expect(banner).toHaveTextContent('TM101 should be 2.00.');
    expect(screen.getByLabelText('Grade for TM101')).toBeEnabled();
  });
});
