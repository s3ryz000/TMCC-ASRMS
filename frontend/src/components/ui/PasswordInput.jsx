import React, { useState } from 'react';
import { FiEye, FiEyeOff } from 'react-icons/fi';

/**
 * A password field with a show/hide (eye) button (#106), as on the login
 * page. Takes the same props as an <input>; the button never submits.
 */
const PasswordInput = ({ className = '', disabled, ...props }) => {
  const [visible, setVisible] = useState(false);
  const label = visible ? 'Hide password' : 'Show password';

  return (
    <div className="relative">
      <input {...props} disabled={disabled} type={visible ? 'text' : 'password'} className={`${className} pr-10`} />
      <button
        type="button"
        onClick={() => setVisible((v) => !v)}
        className="absolute inset-y-0 right-0 flex items-center px-3 text-gray-500 hover:text-gray-700 focus:outline-none focus:text-tmcc"
        aria-label={label}
        aria-pressed={visible}
        title={label}
        disabled={disabled}
      >
        {visible ? <FiEyeOff aria-hidden="true" /> : <FiEye aria-hidden="true" />}
      </button>
    </div>
  );
};

export default PasswordInput;
