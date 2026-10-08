import React from 'react';
import { FiClock, FiCheckCircle, FiXCircle, FiRotateCcw } from 'react-icons/fi';

/** Profile update request statuses (#88), in the order the registrar filters them. */
export const PROFILE_UPDATE_STATUSES = [
  { value: 'pending', label: 'Pending', icon: FiClock, className: 'bg-yellow-50 text-yellow-700 border-yellow-200' },
  { value: 'revision_required', label: 'Revision Required', icon: FiRotateCcw, className: 'bg-orange-50 text-orange-700 border-orange-200' },
  { value: 'approved', label: 'Approved', icon: FiCheckCircle, className: 'bg-green-50 text-green-700 border-green-200' },
  { value: 'rejected', label: 'Rejected', icon: FiXCircle, className: 'bg-red-50 text-red-700 border-red-200' },
];

const ProfileUpdateStatusBadge = ({ status }) => {
  const s = PROFILE_UPDATE_STATUSES.find((x) => x.value === status);
  if (!s) return <span className="text-gray-600 capitalize">{status}</span>;
  const Icon = s.icon;
  return (
    <span className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-full border text-xs font-semibold whitespace-nowrap ${s.className}`}>
      <Icon aria-hidden /> {s.label}
    </span>
  );
};

export default ProfileUpdateStatusBadge;
