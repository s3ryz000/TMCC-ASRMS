import React, { useState, useEffect } from 'react';
import Modal from '../ui/Modal';
import { adminToast } from '../../lib/notifications';
import { parseApiError } from '../../lib/api/errors';
import { adminApi } from '../../lib/api/adminApi';
import { useAuth } from '../../contexts/AuthContext';

const ROLES = [
  { value: 'student', label: 'Student' },
  { value: 'staff', label: 'Staff' },
  { value: 'admin', label: 'Admin' },
];

const EditUserModal = ({ isOpen, onClose, user, onSuccess }) => {
  const [form, setForm] = useState({
    name: '',
    email: '',
    role: 'staff',
    department: '',
    status: 'active',
    password: '',
    passwordConfirmation: '',
  });
  const [errors, setErrors] = useState({});
  const [loading, setLoading] = useState(false);
  // Admins can't change their own role or status (#81); the server refuses it too.
  const { user: currentUser } = useAuth();
  const isSelf = Boolean(user && currentUser && String(user.id) === String(currentUser.id));

  useEffect(() => {
    if (user) {
      setForm({
        name: user.name || '',
        email: user.email || '',
        role: user.role || 'staff',
        department: user.department || '',
        status: user.status || 'active',
        // Never pre-filled: an empty field means "leave the password alone".
        password: '',
        passwordConfirmation: '',
      });
    }
  }, [user]);

  const validate = () => {
    const err = {};
    if (!form.name?.trim()) err.name = 'Name is required.';
    if (!form.email?.trim()) err.email = 'Email is required.';
    else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email)) err.email = 'Invalid email format.';
    if (['staff', 'admin'].includes(form.role) && !form.department?.trim()) {
      err.department = 'Department is required for staff/admin.';
    }
    if (form.password) {
      if (form.password.length < 8) {
        err.password = 'Password must be at least 8 characters.';
      } else if (form.password !== form.passwordConfirmation) {
        err.password_confirmation = 'Passwords do not match.';
      }
    }
    setErrors(err);
    return Object.keys(err).length === 0;
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    setErrors({});
    if (!validate()) return;
    setLoading(true);
    try {
      const passwordWasReset = Boolean(form.password);

      await adminApi.updateUser(user.id, {
        name: form.name.trim(),
        email: form.email.trim(),
        role: form.role,
        department: ['staff', 'admin'].includes(form.role) ? form.department?.trim() || null : null,
        status: form.status,
        // Only sent when the administrator actually typed a new password, so a
        // routine edit never touches the stored credential.
        ...(passwordWasReset && {
          password: form.password,
          password_confirmation: form.passwordConfirmation,
        }),
      });
      onClose();
      adminToast.success(
        'User updated',
        passwordWasReset
          ? `${form.name} has been updated and their password was reset.`
          : `${form.name} has been updated successfully.`
      );
      onSuccess?.();
    } catch (err) {
      const parsed = parseApiError(err);
      if (parsed.errors) {
        const next = {};
        Object.entries(parsed.errors).forEach(([k, msgs]) => {
          next[k] = Array.isArray(msgs) ? msgs[0] : String(msgs);
        });
        setErrors(next);
      } else {
        adminToast.error('Could not update user', parsed.message);
      }
    } finally {
      setLoading(false);
    }
  };

  const inputBase = 'w-full py-2.5 px-4 rounded-lg border text-base focus:outline-none focus:ring-2 focus:ring-tmcc/20 focus:border-tmcc';
  const inputError = 'border-red-500 bg-red-50';
  const inputNormal = 'border-gray-300';

  if (!user) return null;

  return (
    <Modal isOpen={isOpen} onClose={onClose} title="Edit User" titleId="edit-user-title" maxWidth="max-w-lg">
      <form onSubmit={handleSubmit} className="p-6">
        <div className="space-y-4">
          <div>
            <label htmlFor="edit-user-name" className="block text-sm font-medium text-gray-700 mb-1">Full name</label>
            <input
              id="edit-user-name"
              type="text"
              value={form.name}
              onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))}
              className={errors.name ? `${inputBase} ${inputError}` : `${inputBase} ${inputNormal}`}
              aria-invalid={!!errors.name}
            />
            {errors.name && <p className="mt-1 text-xs text-red-600">{errors.name}</p>}
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Username</label>
            <p className="py-2 text-gray-600 text-sm">{user.username}</p>
          </div>
          <div>
            <label htmlFor="edit-user-email" className="block text-sm font-medium text-gray-700 mb-1">Email</label>
            <input
              id="edit-user-email"
              type="email"
              value={form.email}
              onChange={(e) => setForm((f) => ({ ...f, email: e.target.value }))}
              className={errors.email ? `${inputBase} ${inputError}` : `${inputBase} ${inputNormal}`}
              aria-invalid={!!errors.email}
            />
            {errors.email && <p className="mt-1 text-xs text-red-600">{errors.email}</p>}
          </div>
          <div>
            <label htmlFor="edit-user-role" className="block text-sm font-medium text-gray-700 mb-1">Role</label>
            <select
              id="edit-user-role"
              value={form.role}
              onChange={(e) => setForm((f) => ({ ...f, role: e.target.value }))}
              className={`${inputBase} ${inputNormal} disabled:bg-gray-100 disabled:text-gray-500`}
              disabled={isSelf}
              aria-describedby={isSelf ? 'edit-user-self-note' : undefined}
            >
              {ROLES.map((r) => (
                <option key={r.value} value={r.value}>{r.label}</option>
              ))}
            </select>
          </div>
          {['staff', 'admin'].includes(form.role) && (
            <div>
              <label htmlFor="edit-user-department" className="block text-sm font-medium text-gray-700 mb-1">Department</label>
              <input
                id="edit-user-department"
                type="text"
                value={form.department}
                onChange={(e) => setForm((f) => ({ ...f, department: e.target.value }))}
                className={errors.department ? `${inputBase} ${inputError}` : `${inputBase} ${inputNormal}`}
                placeholder="Registrar's Office"
                aria-invalid={!!errors.department}
              />
              {errors.department && <p className="mt-1 text-xs text-red-600">{errors.department}</p>}
            </div>
          )}
          <div>
            <label htmlFor="edit-user-status" className="block text-sm font-medium text-gray-700 mb-1">Status</label>
            <select
              id="edit-user-status"
              value={form.status}
              onChange={(e) => setForm((f) => ({ ...f, status: e.target.value }))}
              className={`${inputBase} ${inputNormal} disabled:bg-gray-100 disabled:text-gray-500`}
              disabled={isSelf}
              aria-describedby={isSelf ? 'edit-user-self-note' : undefined}
            >
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
            </select>
            {isSelf && (
              <p id="edit-user-self-note" className="mt-1 text-xs text-gray-500">
                You can’t change your own role or status.
              </p>
            )}
          </div>

          <div className="pt-4 border-t border-gray-200">
            <h3 className="text-sm font-semibold text-gray-800">Reset password</h3>
            <p className="mt-1 mb-3 text-xs text-gray-500">
              Optional. Leave both fields blank to keep the current password. Use this
              when a user has forgotten theirs and needs access restored.
            </p>

            <div className="space-y-4">
              <div>
                <label htmlFor="edit-user-password" className="block text-sm font-medium text-gray-700 mb-1">
                  New password
                </label>
                <input
                  id="edit-user-password"
                  type="password"
                  autoComplete="new-password"
                  value={form.password}
                  onChange={(e) => setForm((f) => ({ ...f, password: e.target.value }))}
                  className={errors.password ? `${inputBase} ${inputError}` : `${inputBase} ${inputNormal}`}
                  placeholder="At least 8 characters"
                  aria-invalid={!!errors.password}
                />
                {errors.password && <p className="mt-1 text-xs text-red-600">{errors.password}</p>}
              </div>

              <div>
                <label htmlFor="edit-user-password-confirm" className="block text-sm font-medium text-gray-700 mb-1">
                  Confirm new password
                </label>
                <input
                  id="edit-user-password-confirm"
                  type="password"
                  autoComplete="new-password"
                  value={form.passwordConfirmation}
                  onChange={(e) => setForm((f) => ({ ...f, passwordConfirmation: e.target.value }))}
                  className={errors.password_confirmation ? `${inputBase} ${inputError}` : `${inputBase} ${inputNormal}`}
                  aria-invalid={!!errors.password_confirmation}
                />
                {errors.password_confirmation && (
                  <p className="mt-1 text-xs text-red-600">{errors.password_confirmation}</p>
                )}
              </div>
            </div>
          </div>
        </div>
        <div className="flex gap-3 mt-6 justify-end">
          <button type="button" onClick={onClose} className="py-2.5 px-5 rounded-lg text-sm font-medium bg-gray-200 text-gray-800 hover:bg-gray-300">
            Cancel
          </button>
          <button type="submit" disabled={loading} className="py-2.5 px-5 rounded-lg text-sm font-medium bg-tmcc text-white hover:bg-tmcc-dark focus:ring-2 focus:ring-tmcc/30 disabled:opacity-70">
            {loading ? 'Saving...' : 'Save Changes'}
          </button>
        </div>
      </form>
    </Modal>
  );
};

export default EditUserModal;
