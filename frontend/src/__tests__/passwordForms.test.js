import React from 'react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import StaffChangePasswordModal from '../components/staff/ChangePasswordModal';
import StudentChangePasswordModal from '../components/student/ChangePasswordModal';
import CreateUserModal from '../components/admin/CreateUserModal';
import { PASSWORD_HINT } from '../lib/passwordRule';

/**
 * #87 on screen: every form that sets a password shows the rule under the
 * field, and the change-password dialogs open with every field blank, even
 * after something was typed and the dialog was closed (b9841fc).
 */
jest.mock('../lib/api/client', () => ({
  apiClient: { get: jest.fn(), post: jest.fn(), put: jest.fn(), patch: jest.fn(), delete: jest.fn() },
}));

describe('staff change-password dialog', () => {
  test('shows the rule under the new password and reopens blank', async () => {
    const { rerender } = render(<StaffChangePasswordModal isOpen onClose={() => {}} />);

    expect(screen.getByText(PASSWORD_HINT)).toBeInTheDocument();

    await userEvent.type(screen.getByLabelText('Current password'), 'Old-Secret-12');
    await userEvent.type(screen.getByLabelText(/^New password/), 'Harbor-Lantern-47');
    expect(screen.getByLabelText('Current password')).toHaveValue('Old-Secret-12');

    rerender(<StaffChangePasswordModal isOpen={false} onClose={() => {}} />);
    rerender(<StaffChangePasswordModal isOpen onClose={() => {}} />);

    expect(screen.getByLabelText('Current password')).toHaveValue('');
    expect(screen.getByLabelText(/^New password/)).toHaveValue('');
    expect(screen.getByLabelText(/^Confirm/)).toHaveValue('');
  });
});

describe('student change-password dialog', () => {
  test('reopens on a blank first step and shows the rule on the second', async () => {
    let open = true;
    const close = () => { open = false; };
    const { rerender } = render(<StudentChangePasswordModal isOpen={open} onClose={close} />);

    await userEvent.type(screen.getByLabelText(/password/i), 'Old-Secret-12');
    await userEvent.click(screen.getByRole('button', { name: 'Cancel' }));
    rerender(<StudentChangePasswordModal isOpen={open} onClose={close} />);
    open = true;
    rerender(<StudentChangePasswordModal isOpen={open} onClose={close} />);

    expect(screen.getByLabelText(/password/i)).toHaveValue('');

    await userEvent.type(screen.getByLabelText(/password/i), 'Old-Secret-12');
    await userEvent.click(screen.getByRole('button', { name: 'OK' }));
    expect(screen.getByText(PASSWORD_HINT)).toBeInTheDocument();
  });
});

describe('admin create-user form', () => {
  test('shows the rule under the password field', () => {
    render(<CreateUserModal isOpen onClose={() => {}} onSuccess={() => {}} />);

    const hint = screen.getByText(PASSWORD_HINT);
    expect(hint).toHaveAttribute('id', 'create-user-password-hint');
    expect(screen.getByLabelText('Password')).toBeInTheDocument();
  });
});
