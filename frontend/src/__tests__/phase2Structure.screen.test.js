import React from 'react';
import { configure, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createMemoryRouter, RouterProvider } from 'react-router-dom';
import { routes } from '../routes/Router';
import { apiClient } from '../lib/api/client';
import { queryClient } from '../lib/react-query/queryClient';
import { ROLE_ROUTES } from '../contexts/AuthContext';

configure({ asyncUtilTimeout: 5000 });
jest.setTimeout(15000);

/**
 * Phase 2 foundation on screen: the admin's Schools & Programs page and the
 * portal home for faculty, coordinators and deans. API mocked; made-up data.
 */
jest.mock('../lib/api/client', () => ({
  apiClient: { get: jest.fn(), post: jest.fn(), put: jest.fn(), patch: jest.fn(), delete: jest.fn() },
}));

beforeAll(() => {
  global.ResizeObserver = global.ResizeObserver || class { observe() {} unobserve() {} disconnect() {} };
});

const USERS = {
  admin: { id: 2, name: 'Ada Admin', username: 'uat.admin', role: 'admin', roles: [{ name: 'admin' }] },
  dean: { id: 7, name: 'Dina Dean', username: 'dean01', role: 'dean', roles: [{ name: 'dean' }] },
  faculty: { id: 8, name: 'Fe Faculty', username: 'fac01', role: 'faculty', roles: [{ name: 'faculty' }] },
};
const SCHOOLS = {
  schools: [{
    id: 1, code: 'SHT', name: 'School of Hospitality and Tourism', dean_user_id: 7, archived_at: null, faculty_count: 2,
    dean: { id: 7, name: 'Dina Dean', username: 'dean01' },
    programs: [{ id: 11, code: 'BSHM', name: 'BS Hospitality Management', school_id: 1, coordinators: [] }],
  }],
  unassigned_programs: [{ id: 12, code: 'BSE', name: 'BS Entrepreneurship', school_id: null, coordinators: [] }],
  people: [
    { id: 7, name: 'Dina Dean', username: 'dean01', role: 'dean' },
    { id: 9, name: 'Cora Coordinator', username: 'coord01', role: 'coordinator' },
  ],
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
    '/admin/schools': SCHOOLS,
    '/academic/me': { role: 'dean', role_label: 'Dean', name: 'Dina Dean', faculty: null, coordinates: [],
      dean_of: { id: 1, code: 'SHT', name: 'School of Hospitality and Tourism', programs: [{ id: 11, code: 'BSHM' }] } },
  };
  apiClient.get.mockImplementation((url) => Promise.resolve({ data: responses[url] ?? {} }));
  apiClient.put.mockResolvedValue({ data: {} });
});

afterEach(() => {
  sessionStorage.clear();
  queryClient.clear();
  jest.clearAllMocks();
});

test('faculty, coordinators and deans go to the academic portal', () => {
  expect(ROLE_ROUTES.faculty).toBe('/academic');
  expect(ROLE_ROUTES.coordinator).toBe('/academic');
  expect(ROLE_ROUTES.dean).toBe('/academic');
});

test('a dean sees the school they lead and its programs', async () => {
  signIn('dean');
  renderAt('/academic');

  expect(await screen.findByRole('heading', { name: 'Dean portal' })).toBeInTheDocument();
  expect(screen.getByText('School of Hospitality and Tourism')).toBeInTheDocument();
  expect(screen.getByText('Programs: BSHM')).toBeInTheDocument();
});

test('an academic account is kept out of the registrar and admin portals', async () => {
  signIn('faculty');
  const router = renderAt('/staff');

  await waitFor(() => expect(router.state.location.pathname).toBe('/academic'));
});

test('the admin sees schools, deans and programs, and moves a program into a school', async () => {
  signIn('admin');
  renderAt('/admin/schools');

  expect(await screen.findByRole('heading', { name: /SHT — School of Hospitality and Tourism/ })).toBeInTheDocument();
  expect(screen.getByLabelText('Dean of SHT')).toHaveValue('7');
  expect(screen.getByText('Programs not in a school')).toBeInTheDocument();

  await userEvent.selectOptions(screen.getByLabelText('School of BSE'), '1');
  await waitFor(() => expect(apiClient.put).toHaveBeenCalledWith('/admin/programs/12/school', { school_id: 1 }));

  await userEvent.selectOptions(screen.getByLabelText('Coordinator of BSHM'), '9');
  await waitFor(() => expect(apiClient.put).toHaveBeenCalledWith('/admin/programs/11/coordinators', { user_ids: [9] }));
});
