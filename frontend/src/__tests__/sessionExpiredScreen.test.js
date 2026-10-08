import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { AxiosError } from 'axios';
import { createMemoryRouter, RouterProvider } from 'react-router-dom';
import { routes } from '../routes/Router';
import { apiClient } from '../lib/api/client';
import { queryClient } from '../lib/react-query/queryClient';
import { SESSION_EXPIRED_MESSAGE } from '../lib/sessionExpiry';

/**
 * #86 on screen: the real API client answers a 401. Only the network adapter
 * is replaced, so the client's own 401 handling and AuthContext run as in the
 * app. An expired session returns to the login page with the message, once;
 * a failed sign-in does not count as an expiry.
 */
// Jest 27 can't load axios's ES-module entry; its CommonJS build is the same client.
jest.mock('axios', () => jest.requireActual('axios/dist/node/axios.cjs'));

const STAFF = { id: 1, name: 'Rita Registrar', username: 'uat.staff', role: 'staff', roles: [{ name: 'staff' }] };

let requests;
const answer401 = (config) => {
  requests.push(config.url);
  const response = { status: 401, statusText: 'Unauthorized', data: { message: 'Unauthenticated.' }, headers: {}, config };
  return Promise.reject(new AxiosError('Request failed with status code 401', 'ERR_BAD_REQUEST', config, null, response));
};
const originalAdapter = apiClient.defaults.adapter;

beforeEach(() => {
  requests = [];
  apiClient.defaults.adapter = answer401;
});

afterEach(() => {
  apiClient.defaults.adapter = originalAdapter;
  sessionStorage.clear();
  queryClient.clear();
});

test('an expired session returns to the login page with the message, without a loop', async () => {
  sessionStorage.setItem('auth_token', 'expired-token');
  sessionStorage.setItem('auth_user', JSON.stringify(STAFF));
  const router = createMemoryRouter(routes, { initialEntries: ['/staff'] });

  render(<RouterProvider router={router} />);

  expect(await screen.findByText(SESSION_EXPIRED_MESSAGE)).toBeInTheDocument();
  expect(router.state.location.pathname).toBe('/');
  expect(screen.getByRole('form', { name: 'Sign in form' })).toBeInTheDocument();
  expect(sessionStorage.getItem('auth_token')).toBeNull();
  expect(sessionStorage.getItem('auth_user')).toBeNull();

  // Settled on the login page: no further requests, no redirect back and forth.
  const settled = requests.length;
  await new Promise((resolve) => setTimeout(resolve, 400));
  expect(requests.length).toBe(settled);
  expect(router.state.location.pathname).toBe('/');
});

test('a failed sign-in (401 from the login request) is not shown as an expired session', async () => {
  const router = createMemoryRouter(routes, { initialEntries: ['/'] });
  render(<RouterProvider router={router} />);

  await userEvent.type(screen.getByLabelText('Username'), 'uat.staff');
  await userEvent.type(screen.getByLabelText('Password'), 'not-the-password');
  await userEvent.click(screen.getByRole('button', { name: 'Sign in' }));

  expect(await screen.findByRole('alert')).toBeInTheDocument();
  expect(screen.queryByText(SESSION_EXPIRED_MESSAGE)).not.toBeInTheDocument();
  expect(router.state.location.pathname).toBe('/');
  expect(requests).toEqual(['/auth/login']);
});
