import React, { useState } from 'react';
import Modal from '../ui/Modal';
import { adminToast } from '../../lib/notifications';
import { parseApiError } from '../../lib/api/errors';
import { PASSWORD_HINT, passwordProblem } from '../../lib/passwordRule';
import { adminApi } from '../../lib/api/adminApi';

const ROLES = [
  { value: 'staff', label: 'Staff' },
  { value: 'admin', label: 'Admin' },
];

const CreateUserModal = ({ isOpen, onClose, onSuccess }) => {
  const [form, setForm] = useState({
    name: '',
    username: '',
    email: '',
    password: '',
    role: 'staff',
    department: '',
  });
  const [errors, setErrors] = useState({});
  const [loading, setLoading] = useState(false);

  const validate = () => {
    const err = {};
    if (!form.name?.trim()) err.name = 'Name is required.';
    if (!form.username?.trim()) err.username = 'Username is required.';
    if (!form.email?.trim()) err.email = 'Email is required.';
    else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email)) err.email = 'Invalid email format.';
    if (!form.password?.trim()) err.password = 'Password is required.';
    else if (passwordProblem(form.password)) err.password = passwordProblem(form.password);
    if (['staff', 'admin'].includes(form.role) && !form.department?.trim()) {
      err.department = 'Department is required for staff/admin.';
    }
    setErrors(err);
    return Object.keys(err).length === 0;
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    setErrors({});
    if (!validate()) return;
    setLoading(true);
    const createdName = form.name;
    try {
      await adminApi.createUser({
        name: form.name.trim(),
        username: form.username.trim(),
        email: form.email.trim(),
        password: form.password,
        password_confirmation: form.password,
        role: form.role,
        department: ['staff', 'admin'].includes(form.role) ? form.department?.trim() || null : null,
      });
      onClose();
      setForm({ name: '', username: '', email: '', password: '', role: 'staff', department: '' });
      adminToast.success('User created', `${createdName} has been added successfully.`);
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
        adminToast.error('Could not create user', parsed.message);
      }
    } finally {
      setLoading(false);
    }
  };

  const inputBase = 'w-full py-2.5 px-4 rounded-lg border text-base focus:outline-none focus:ring-2 focus:ring-tmcc/20 focus:border-tmcc';
  const inputError = 'border-red-500 bg-red-50';
  const inputNormal = 'border-gray-300';

  return (
    <Modal isOpen={isOpen} onClose={onClose} title="Create User" titleId="create-user-title" maxWidth="max-w-lg">
      <form onSubmit={handleSubmit} className="p-6">
        <div className="space-y-4">
          <div>
            <label htmlFor="create-user-name" className="block text-sm font-medium text-gray-700 mb-1">Full name</label>
            <input
              id="create-user-name"
              type="text"
              value={form.name}
              onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))}
              className={errors.name ? `${inputBase} ${inputError}` : `${inputBase} ${inputNormal}`}
              placeholder="Juan Dela Cruz"
              aria-invalid={!!errors.name}
            />
            {errors.name && <p className="mt-1 text-xs text-red-600">{errors.name}</p>}
          </div>
          <div>
            <label htmlFor="create-user-username" className="block text-sm font-medium text-gray-700 mb-1">Username</label>
            <input
              id="create-user-username"
              type="text"
              value={form.username}
              onChange={(e) => setForm((f) => ({ ...f, username: e.target.value }))}
              className={errors.username ? `${inputBase} ${inputError}` : `${inputBase} ${inputNormal}`}
              placeholder="juan.delacruz"
              aria-invalid={!!errors.username}
            />
            {errors.username && <p className="mt-1 text-xs text-red-600">{errors.username}</p>}
          </div>
          <div>
            <label htmlFor="create-user-email" className="block text-sm font-medium text-gray-700 mb-1">Email</label>
            <input
              id="create-user-email"
              type="email"
              value={form.email}
              onChange={(e) => setForm((f) => ({ ...f, email: e.target.value }))}
              className={errors.email ? `${inputBase} ${inputError}` : `${inputBase} ${inputNormal}`}
              placeholder="juan@tmcc.edu.ph"
              aria-invalid={!!errors.email}
            />
            {errors.email && <p className="mt-1 text-xs text-red-600">{errors.email}</p>}
          </div>
          <div>
            <label htmlFor="create-user-password" className="block text-sm font-medium text-gray-700 mb-1">Password</label>
            <input
              id="create-user-password"
              type="password"
              value={form.password}
              onChange={(e) => setForm((f) => ({ ...f, password: e.target.value }))}
              className={errors.password ? `${inputBase} ${inputError}` : `${inputBase} ${inputNormal}`}
              placeholder="••••••••"
              aria-describedby="create-user-password-hint"
              aria-invalid={!!errors.password}
            />
            <p id="create-user-password-hint" className="mt-1 text-xs text-gray-500">{PASSWORD_HINT}</p>
            {errors.password && <p className="mt-1 text-xs text-red-600">{errors.password}</p>}
          </div>
          <div>
            <label htmlFor="create-user-role" className="block text-sm font-medium text-gray-700 mb-1">Role</label>
            <select
              id="create-user-role"
              value={form.role}
              onChange={(e) => setForm((f) => ({ ...f, role: e.target.value }))}
              className={`${inputBase} ${inputNormal}`}
            >
              {ROLES.map((r) => (
                <option key={r.value} value={r.value}>{r.label}</option>
              ))}
            </select>
          </div>
          {['staff', 'admin'].includes(form.role) && (
            <div>
              <label htmlFor="create-user-department" className="block text-sm font-medium text-gray-700 mb-1">Department</label>
              <input
                id="create-user-department"
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
        </div>
        <div className="flex gap-3 mt-6 justify-end">
          <button type="button" onClick={onClose} className="py-2.5 px-5 rounded-lg text-sm font-medium bg-gray-200 text-gray-800 hover:bg-gray-300">
            Cancel
          </button>
          <button type="submit" disabled={loading} className="py-2.5 px-5 rounded-lg text-sm font-medium bg-tmcc text-white hover:bg-tmcc-dark focus:ring-2 focus:ring-tmcc/30 disabled:opacity-70">
            {loading ? 'Creating...' : 'Create User'}
          </button>
        </div>
      </form>
    </Modal>
  );
};

export default CreateUserModal;
