import React from 'react';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createMemoryRouter, RouterProvider } from 'react-router-dom';
import { routes } from '../routes/Router';
import { apiClient } from '../lib/api/client';
import { queryClient } from '../lib/react-query/queryClient';

/**
 * Screens for #94 (log viewer, My Activity) and #96 (reports with a date
 * range), on the real routes with the API client mocked. Made-up data.
 */
jest.mock('../lib/api/client', () => ({
  apiClient: { get: jest.fn(), post: jest.fn(), put: jest.fn(), patch: jest.fn(), delete: jest.fn() },
}));

beforeAll(() => {
  global.ResizeObserver = global.ResizeObserver || class { observe() {} unobserve() {} disconnect() {} };
  global.URL.createObjectURL = jest.fn(() => 'blob:report');
  global.URL.revokeObjectURL = jest.fn();
  // Downloads click a link; jsdom can't navigate to a blob.
  jest.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {});
});

const USERS = {
  admin: { id: 2, name: 'Ada Admin', username: 'uat.admin', role: 'admin', roles: [{ name: 'admin' }] },
  staff: { id: 1, name: 'Rita Registrar', username: 'uat.staff', role: 'staff', roles: [{ name: 'staff' }] },
};
const LOG_ROW = {
  log_id: 41, action: 'Login: uat.staff', user_id: 1, user_name: 'Rita Registrar', username: 'uat.staff',
  role: 'staff', logged_at: '2026-10-08 09:15:30', logged_at_label: 'Oct 8, 2026 9:15:30 AM',
};
const PAGE = (rows) => ({ data: rows, total: rows.length, from: 1, to: rows.length, current_page: 1, last_page: 1, per_page: 25 });

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
const callsTo = (url) => apiClient.get.mock.calls.filter(([u]) => u === url).map(([, config]) => config?.params ?? {});

beforeEach(() => {
  responses = {
    '/dashboard': { kpis: {}, recent_activity: [] },
    '/admin/logs': PAGE([LOG_ROW]),
    '/admin/logs/users': { users: [{ id: 1, name: 'Rita Registrar', username: 'uat.staff', role: 'staff' }, { id: 2, name: 'Ada Admin', username: 'uat.admin', role: 'admin' }] },
    '/staff/my-activity': PAGE([LOG_ROW]),
    '/admin/logs/export-pdf': new Blob(['%PDF-1.4']),
    '/admin/reports/requests': { range: {}, total: 3, by_status: { pending: 1, approved: 1, rejected: 0, released: 1 }, by_record_type: [{ record_type: 'transcript', total: 3 }], decided: 2, approval_rate: 100, avg_processing_time_days: 2 },
    '/admin/reports/activity': { total: 5, by_day: [{ date: '2026-10-08', total: 5 }], by_role: [{ role: 'staff', total: 5 }], top_actions: [{ action: 'Login', total: 5 }] },
    '/admin/reports/export': { export_data: [], summary: {} },
  };
  apiClient.get.mockImplementation((url) => Promise.resolve({ data: responses[url] ?? {} }));
});

afterEach(() => {
  sessionStorage.clear();
  queryClient.clear();
  jest.clearAllMocks();
});

// ------------------------------------------------------------------ #94

test('#94 the admin log shows names, roles and Manila time, and every filter goes to the server', async () => {
  signIn('admin');
  renderAt('/admin/logs');

  const table = (await screen.findByText('Login: uat.staff')).closest('table');
  const headers = within(table).getAllByRole('columnheader').map((h) => h.textContent);
  expect(headers).toEqual(['Date & Time', 'User', 'Role', 'Action', 'Log ID']);
  const row = within(table).getByText('Login: uat.staff').closest('tr');
  expect(row).toHaveTextContent('Oct 8, 2026 9:15:30 AM');
  expect(row).toHaveTextContent('Rita Registrar');
  expect(row).toHaveTextContent('uat.staff');

  await userEvent.selectOptions(await screen.findByLabelText('User'), '1');
  await userEvent.selectOptions(screen.getByLabelText('Role'), 'staff');
  await userEvent.type(screen.getByLabelText(/Action contains/), 'Login');
  fireEvent.change(screen.getByLabelText('From'), { target: { value: '2026-10-01' } });
  fireEvent.change(screen.getByLabelText('To'), { target: { value: '2026-10-08' } });

  await waitFor(() => expect(callsTo('/admin/logs')).toContainEqual(expect.objectContaining({
    user_id: '1', role: 'staff', q: 'Login', date_from: '2026-10-01', date_to: '2026-10-08',
  })));

  await userEvent.click(screen.getByRole('button', { name: /Export PDF/ }));
  await waitFor(() => expect(callsTo('/admin/logs/export-pdf')).toContainEqual({
    user_id: '1', role: 'staff', q: 'Login', date_from: '2026-10-01', date_to: '2026-10-08',
  }));
});

test('#94 the staff sidebar has My Activity, which lists the user\'s own entries without a user filter', async () => {
  signIn('staff');
  const router = renderAt('/staff');

  const nav = await screen.findByRole('navigation', { name: 'Staff dashboard navigation' });
  await userEvent.click(within(nav).getByRole('link', { name: /My Activity/ }));
  await waitFor(() => expect(router.state.location.pathname).toBe('/staff/my-activity'));

  expect(await screen.findByRole('heading', { name: 'My Activity' })).toBeInTheDocument();
  expect(await screen.findByText('Login: uat.staff')).toBeInTheDocument();
  expect(screen.queryByLabelText('User')).not.toBeInTheDocument();
  expect(screen.getByLabelText('Role')).toBeInTheDocument();
});

// ------------------------------------------------------------------ #96

test('#96 Reports has a date range, the request and activity sections, and exports the same range', async () => {
  signIn('admin');
  renderAt('/admin/reports');

  expect(await screen.findByRole('heading', { name: 'Record requests' })).toBeInTheDocument();
  expect(screen.getByRole('heading', { name: 'System activity' })).toBeInTheDocument();
  expect(screen.getByRole('heading', { name: 'Log history' })).toBeInTheDocument();

  fireEvent.change(screen.getByLabelText('From'), { target: { value: '2026-09-01' } });
  fireEvent.change(screen.getByLabelText('To'), { target: { value: '2026-09-30' } });
  await userEvent.click(screen.getByRole('button', { name: 'Show report' }));

  const range = { date_from: '2026-09-01', date_to: '2026-09-30' };
  await waitFor(() => expect(callsTo('/admin/reports/requests')).toContainEqual(expect.objectContaining(range)));
  expect(callsTo('/admin/reports/activity')).toContainEqual(expect.objectContaining(range));

  await userEvent.click(screen.getByRole('button', { name: /Export CSV/ }));
  await waitFor(() => expect(callsTo('/admin/reports/export')).toContainEqual(expect.objectContaining(range)));
});
