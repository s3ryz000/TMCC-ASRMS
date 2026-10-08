import React from 'react';
import { fireEvent, configure, render, screen, waitFor, within } from '@testing-library/react';

// The portal layouts render after their first requests resolve; on a busy CI
// machine that can take over a second, so lookups wait up to 5 s.
configure({ asyncUtilTimeout: 5000 });
jest.setTimeout(15000);
import userEvent from '@testing-library/user-event';
import { createMemoryRouter, RouterProvider } from 'react-router-dom';
import { routes } from '../routes/Router';
import { apiClient } from '../lib/api/client';
import { queryClient } from '../lib/react-query/queryClient';
import StudentNumberConflictCard from '../components/staff/StudentNumberConflictCard';

/**
 * Screens for #55 (Edit Student hub), #56 (student number) and #57 (Manage
 * Records), on the real routes with the API client mocked. The data is made
 * up; the shapes follow the API.
 */
jest.mock('../lib/api/client', () => ({
  apiClient: { get: jest.fn(), post: jest.fn(), put: jest.fn(), patch: jest.fn(), delete: jest.fn() },
}));

const USERS = {
  staff: { id: 1, name: 'Rita Registrar', username: 'uat.staff', role: 'staff', roles: [{ name: 'staff' }] },
  admin: { id: 2, name: 'Ada Admin', username: 'uat.admin', role: 'admin', roles: [{ name: 'admin' }] },
  student: { id: 3, name: 'Test Student', username: '269901', role: 'student', roles: [{ name: 'student' }] },
};

const STUDENT = {
  student_id: 3, student_number: '269901', first_name: 'Tess', last_name: 'Tester', middle_name: null,
  date_of_birth: '2006-01-02', email: 'tess@example.test', contact_number: '09170000000', address: 'Test Street',
  enrollment_date: '2026-08-10', sex: 'F', program_id: 1,
  program: { id: 1, code: 'BSTM', name: 'BS Tourism Management' },
  user: { id: 30, name: 'Tess Tester', username: '269901', email: 'tess@example.test' },
  archive_records: { archive_id: 1, student_id: 3, record_type: 'Form 137', cabinet_no: 'C1', shelf_no: 'S1', folder_code: 'F1', document_status: 'Complete' },
  enrollments: [], grades: [],
};

const ACADEMIC = {
  student: { student_number: '269901', name: 'Tess Tester', program: 'BS Tourism Management', program_code: 'BSTM', enrollment_date: '2026-08-10' },
  summary: {
    overall_gwa: 1.4,
    latin_honors: { eligible: false, reason: 'Not yet graduated', honor: null },
    terms: [{ academic_year: '2026-2027', semester: '1', gpa: 1.4, deans_list: { eligible: true, reason: '' } }],
    years: [{ academic_year: '2026-2027', gpa: 1.4, presidents_list: { eligible: false, reason: 'Year not complete' } }],
  },
  curriculum: { roadmap: [], total_curriculum_units: 0, completed_units: 0, units_left: 0, failed_subjects_count: 0 },
  notifications: [],
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
    '/dashboard': { kpis: {}, recent_activity: [] },
    '/staff/students': { current_page: 1, data: [STUDENT], from: 1, to: 1, total: 1, last_page: 1, per_page: 10 },
    '/staff/students/3': { student: STUDENT },
    '/staff/students/3/academic-summary': ACADEMIC,
    '/staff/students/3/documents': [],
    '/staff/programs': { programs: [{ id: 1, code: 'BSTM', name: 'BS Tourism Management', archived: false }] },
  };
  apiClient.get.mockImplementation((url, config) => {
    if (url === '/staff/student-numbers/check') {
      const number = config?.params?.number;
      return Promise.resolve({
        data: number === '269903'
          ? { number, available: false, conflict: { name: 'Carl Holder', program: 'BSTM' }, next_available: '269904' }
          : { number, available: true, next_available: '269904' },
      });
    }
    return Promise.resolve({ data: responses[url] ?? {} });
  });
});

afterEach(() => {
  sessionStorage.clear();
  queryClient.clear();
  jest.clearAllMocks();
});

// ------------------------------------------------------------------ #57

describe('#57 Manage Records', () => {
  test('the registrar menu has New Student and Manage Records, and the old URLs land on Manage Records', async () => {
    signIn('staff');
    const router = renderAt('/staff/students');

    await waitFor(() => expect(router.state.location.pathname).toBe('/staff/records'));
    const nav = screen.getByRole('navigation', { name: 'Staff dashboard navigation' });
    expect(within(nav).getByRole('link', { name: /New Student/ })).toHaveAttribute('href', '/staff/students/new');
    expect(within(nav).getByRole('link', { name: /Manage Records/ })).toHaveAttribute('href', '/staff/records');

    await router.navigate('/staff/view-records');
    await waitFor(() => expect(router.state.location.pathname).toBe('/staff/records'));
  });

  test('list rows have no action buttons and a row opens the record page', async () => {
    signIn('staff');
    const router = renderAt('/staff/records');

    const name = await screen.findByText(/Tester/);
    const row = name.closest('tr');
    expect(within(row).queryAllByRole('button')).toHaveLength(0);

    await userEvent.click(within(row).getByText('269901'));
    await waitFor(() => expect(router.state.location.pathname).toBe('/staff/records/3'));
  });

  test('a direct link loads the record with its four sections, awards from the API and registrar actions', async () => {
    signIn('staff');
    renderAt('/staff/records/3');

    for (const title of ['Student Information', 'Subjects & Grades', 'Documents & Awards', 'Archive Record']) {
      expect(await screen.findByRole('heading', { name: title })).toBeInTheDocument();
    }
    expect(apiClient.get).toHaveBeenCalledWith('/staff/students/3');
    expect(apiClient.get).toHaveBeenCalledWith('/staff/students/3/academic-summary');
    expect(await screen.findByText(/Dean's List/)).toBeInTheDocument();
    expect(screen.queryByText(/President's List/)).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Archive Record/ })).toBeInTheDocument();
    // #97: the registrar edits the archive location here.
    expect(screen.getByRole('button', { name: /Edit location/ })).toBeInTheDocument();
  });

  test('an admin sees the record read-only, with no Edit or Archive', async () => {
    signIn('admin');
    renderAt('/staff/records/3');

    expect(await screen.findByText(/Read-only: changes to student records are made by the registrar/)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Archive Record/ })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Edit location/ })).not.toBeInTheDocument();
    expect(screen.queryByRole('link', { name: /^Edit/ })).not.toBeInTheDocument();
  });

  test('a student is redirected away from the record page', async () => {
    signIn('student');
    const router = renderAt('/staff/records/3');

    await waitFor(() => expect(router.state.location.pathname).toBe('/dashboard'));
  });
});

// ------------------------------------------------------------------ #55

describe('#55 Edit Student hub', () => {
  test('the hub shows two cards, no steps and no "thesis" wording', async () => {
    signIn('staff');
    renderAt('/staff/students/3/edit');

    expect(await screen.findByRole('heading', { name: 'Edit Student' })).toBeInTheDocument();
    expect(await screen.findByRole('link', { name: /Student Information/ })).toHaveAttribute('href', '/staff/students/3/edit/information');
    expect(screen.getByRole('link', { name: /Grades & Enrollment/ })).toHaveAttribute('href', '/staff/students/3/edit/grades');
    expect(document.body.textContent).not.toMatch(/\bStep \d/i);
    expect(document.body.textContent).not.toMatch(/per ASRMS thesis requirements/i);
  });

  test('Previous returns to the hub without edits', async () => {
    signIn('staff');
    const router = renderAt('/staff/students/3/edit/information');

    await screen.findByDisplayValue('Test Street');
    await userEvent.click(screen.getByRole('button', { name: /Previous/ }));

    await waitFor(() => expect(router.state.location.pathname).toBe('/staff/students/3/edit'));
  });

  test('leaving the Information page with edits asks first, then returns to the hub', async () => {
    signIn('staff');
    const router = renderAt('/staff/students/3/edit/information');

    const address = await screen.findByDisplayValue('Test Street');
    const previous = screen.getByRole('button', { name: /Previous/ });
    await userEvent.clear(address);
    await userEvent.type(address, 'New Street');
    await userEvent.click(previous);

    expect(await screen.findByText('Leave without saving?')).toBeInTheDocument();
    expect(router.state.location.pathname).toBe('/staff/students/3/edit/information');
    await userEvent.click(screen.getByRole('button', { name: 'Leave' }));
    await waitFor(() => expect(router.state.location.pathname).toBe('/staff/students/3/edit'));
  });

  test.each(['/staff/students/3/edit', '/staff/students/3/edit/information', '/staff/students/3/edit/grades'])(
    'an admin opening %s gets the read-only record page',
    async (path) => {
      signIn('admin');
      const router = renderAt(path);
      await waitFor(() => expect(router.state.location.pathname).toBe('/staff/records/3'));
    },
  );

  test('a student opening the hub is redirected', async () => {
    signIn('student');
    const router = renderAt('/staff/students/3/edit');
    await waitFor(() => expect(router.state.location.pathname).toBe('/dashboard'));
  });
});

// ------------------------------------------------------------------ #56

describe('#56 student number', () => {
  test('New Student: the year prefix comes from the enrollment date, a taken number offers the next one', async () => {
    signIn('staff');
    renderAt('/staff/students/new');

    const date = await screen.findByLabelText('Enrollment Date *');
    fireEvent.change(date, { target: { value: '2026-08-10' } });
    expect(screen.getByLabelText('Year prefix 26')).toHaveTextContent('26');

    await userEvent.type(screen.getByLabelText('Student Number *'), '9903');
    expect(await screen.findByText(/269903 is taken by Carl Holder \(BSTM\)/)).toBeInTheDocument();

    await userEvent.click(screen.getByRole('button', { name: 'Use next available: 269904' }));
    expect(screen.getByLabelText('Student Number *')).toHaveValue('9904');
    expect(await screen.findByText('269904 is available.')).toBeInTheDocument();
  });

  test('the conflict card compares the holder with the student being entered', async () => {
    const onUseNext = jest.fn();
    render(
      <StudentNumberConflictCard
        conflict={{ student_number: '269903', name: 'Carl Holder', program: 'BSTM', enrollment_date: '2026-08-10', date_of_birth: '2005-05-05' }}
        entered={{ name: 'Tess Tester', program: 'BSTM', enrollment_date: '2026-08-11', date_of_birth: '2006-01-02' }}
        nextAvailable="269904"
        onUseNext={onUseNext}
        onDismiss={() => {}}
      />,
    );

    expect(screen.getByText('Already holds this number')).toBeInTheDocument();
    expect(screen.getByText('Student being entered')).toBeInTheDocument();
    for (const value of ['Carl Holder', 'Tess Tester', '2005-05-05', '2006-01-02']) {
      expect(screen.getByText(value)).toBeInTheDocument();
    }
    await userEvent.click(screen.getByRole('button', { name: 'Use next available: 269904' }));
    expect(onUseNext).toHaveBeenCalled();
  });

  test('the registrar changes a number from the read-only field through the dialog, with a reason', async () => {
    signIn('staff');
    renderAt('/staff/students/3/edit/information');

    const field = await screen.findByDisplayValue('269901');
    expect(field).toHaveAttribute('readonly');
    await userEvent.click(screen.getByRole('button', { name: 'Change Student Number' }));

    const dialog = await screen.findByRole('dialog');
    expect(within(dialog).getByLabelText('Year prefix 26')).toBeInTheDocument();
    expect(within(dialog).getByLabelText('Reason *')).toBeInTheDocument();
  });
});
