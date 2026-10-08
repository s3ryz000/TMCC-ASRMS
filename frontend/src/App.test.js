import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createMemoryRouter, RouterProvider } from 'react-router-dom';
import { routes } from './routes/Router';
import { apiClient } from './lib/api/client';
import { queryClient } from './lib/react-query/queryClient';

/**
 * Smoke tests for the app as it is mounted (#39): the real route table in a
 * memory router, with the API client mocked so nothing reaches a server.
 */
jest.mock('./lib/api/client', () => ({
  apiClient: { get: jest.fn(), post: jest.fn(), put: jest.fn(), patch: jest.fn(), delete: jest.fn() },
}));

const STAFF = { id: 1, name: 'Rita Registrar', username: 'uat.staff', role: 'staff', roles: [{ name: 'staff' }] };
const STUDENT = { id: 2, name: 'Ana Reyes', username: '260001', role: 'student', roles: [{ name: 'student' }] };

/** GET responses by URL; anything else answers with an empty object. */
let responses = {};

const signIn = (user) => {
  sessionStorage.setItem('auth_token', 'test-token');
  sessionStorage.setItem('auth_user', JSON.stringify(user));
  responses['/user'] = user;
};

const renderAt = (path) => {
  const router = createMemoryRouter(routes, { initialEntries: [path] });
  render(<RouterProvider router={router} />);
  return router;
};

beforeEach(() => {
  responses = {};
  apiClient.get.mockImplementation((url) => Promise.resolve({ data: responses[url] ?? {} }));
});

afterEach(() => {
  sessionStorage.clear();
  queryClient.clear();
  jest.clearAllMocks();
});

describe('login page', () => {
  test('renders the sign-in form and asks for a username on an empty submit', async () => {
    renderAt('/');

    expect(screen.getByRole('form', { name: 'Sign in form' })).toBeInTheDocument();
    expect(screen.getByLabelText('Username')).toBeInTheDocument();
    expect(screen.getByLabelText('Password')).toBeInTheDocument();

    await userEvent.click(screen.getByRole('button', { name: 'Sign in' }));

    expect(await screen.findByRole('alert')).toHaveTextContent('Please enter your username.');
    expect(apiClient.post).not.toHaveBeenCalled();
  });
});

describe('protected areas', () => {
  test.each(['/staff', '/staff/records', '/admin', '/admin/logs'])('a logged-out visit to %s goes to the login page', async (path) => {
    const router = renderAt(path);

    expect(await screen.findByRole('form', { name: 'Sign in form' })).toBeInTheDocument();
    expect(router.state.location.pathname).toBe('/');
  });

  test('a student session is sent away from the registrar pages', async () => {
    signIn(STUDENT);

    const router = renderAt('/staff/catalog/subjects/view');

    await waitFor(() => expect(router.state.location.pathname).toBe('/dashboard'));
    expect(apiClient.get).not.toHaveBeenCalledWith('/staff/subjects', expect.anything());
  });
});

describe('catalogue pages with mocked data', () => {
  test('the Subjects catalogue lists the subjects', async () => {
    signIn(STAFF);
    responses['/staff/subjects'] = {
      subjects: [{ id: 7, code: 'IT101', title: 'Introduction to Computing', units: 3, programs: ['BSIT'], curriculum_count: 1, grades_count: 0 }],
    };
    responses['/staff/subject-prefixes'] = { prefixes: [] };

    renderAt('/staff/catalog/subjects/view');

    expect(await screen.findByRole('heading', { name: 'Subjects' })).toBeInTheDocument();
    expect(await screen.findByText('IT101')).toBeInTheDocument();
    expect(screen.getByText('Introduction to Computing')).toBeInTheDocument();
  });

  test('a program curriculum shows its subjects by term', async () => {
    signIn(STAFF);
    responses['/staff/programs'] = { programs: [{ id: 5, code: 'BSIT', name: 'BS Information Technology' }] };
    responses['/staff/programs/5/curriculum'] = {
      curriculum: [
        { id: 1, year_level: 1, semester: 1, subject: { code: 'IT101', title: 'Introduction to Computing', units: 3 }, prerequisites: [] },
        { id: 2, year_level: 1, semester: 2, subject: { code: 'IT102', title: 'Computer Programming 1', units: 3 }, prerequisites: [] },
      ],
    };

    renderAt('/staff/catalog/programs/5/curriculum');

    expect(await screen.findByRole('heading', { name: 'BSIT — BS Information Technology curriculum' })).toBeInTheDocument();
    expect(await screen.findByText('IT101')).toBeInTheDocument();
    expect(screen.getByText('Computer Programming 1')).toBeInTheDocument();
  });
});
