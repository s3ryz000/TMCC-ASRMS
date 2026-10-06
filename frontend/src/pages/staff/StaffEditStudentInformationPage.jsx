import React, { useState, useEffect, useRef } from "react";
import { useQueryClient } from "@tanstack/react-query";
import { Link, Navigate, useParams, useNavigate, useBlocker } from "react-router-dom";
import { FiArrowLeft } from "react-icons/fi";
import { staffApi } from "../../lib/api/staffApi";
import { staffToast } from "../../lib/notifications";
import { parseApiError } from "../../lib/api/errors";
import { localDateString, toDateInputValue } from "../../lib/tools";
import { queryKeys } from "../../lib/react-query/queryKeys";
import ChangeStudentNumberDialog from "../../components/staff/ChangeStudentNumberDialog";
import ConfirmDialog from "../../components/ui/ConfirmDialog";
import { useAuth } from "../../contexts/AuthContext";
import { useStudentQuery } from "../../hooks/useStudentQuery";
import { isDirty, previousOf, recordPath } from "../../features/students/studentRoutes";

const defaultForm = {
  student_number: "",
  first_name: "",
  last_name: "",
  date_of_birth: "",
  sex: "",
  email: "",
  contact_number: "",
  address: "",
  enrollment_date: "",
  graduation_date: "",
};

// The API sends calendar dates as "YYYY-MM-DD"; use them as they are. Going
// through new Date(...).toISOString() turned them into the previous day (#77).
const formatDateForInput = toDateInputValue;

/** The form fields from a student as the API returns it. */
const toForm = (s) => {
  const parts = s.name ? s.name.split(" ") : [];
  return {
    student_number: s.student_number ?? s.student_id ?? "",
    first_name: s.first_name ?? parts[0] ?? "",
    last_name: s.last_name ?? parts.slice(1).join(" ") ?? "",
    date_of_birth: formatDateForInput(s.date_of_birth),
    sex: s.sex === 'Male' || s.sex === 'male' ? 'M'
      : s.sex === 'Female' || s.sex === 'female' ? 'F'
        : (s.sex ?? ""),
    email: s.email ?? "",
    contact_number: s.contact_number ?? "",
    address: s.address ?? "",
    enrollment_date: formatDateForInput(s.enrollment_date) || localDateString(),
    graduation_date: formatDateForInput(s.graduation_date),
  };
};

const cardClass = "mb-6 p-6 bg-white rounded-xl border-l-4 border-tmcc shadow-[0_2px_8px_rgba(0,0,0,0.06)] border border-gray-100";
const cardTitleClass = "m-0 mb-5 pb-3 text-base font-semibold text-gray-800 border-b-2 border-gray-200";

/**
 * Edit Student → Student Information (#55): personal, contact and enrollment
 * details on one page (the old steps 1-3), saved with PUT /staff/students/{id}.
 * The student is loaded from the API by id; leaving with unsaved edits asks
 * first. Registrar only.
 */
const StaffEditStudentInformationPage = () => {
  const { id } = useParams();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const { role } = useAuth();
  const student = useStudentQuery(role === "staff" ? id : null);

  const [form, setForm] = useState(defaultForm);
  // What was loaded (or last saved), to tell whether there are unsaved edits.
  const [saved, setSaved] = useState(null);
  const [errors, setErrors] = useState({});
  const [submitStatus, setSubmitStatus] = useState(null);
  const [loading, setLoading] = useState(false);
  // Change Student Number (#56): the dialog and the saved enrollment date the
  // server checks the new number's year against.
  const [changingNumber, setChangingNumber] = useState(false);
  const loadedId = useRef(null);
  const leaving = useRef(false);

  // Fill the form once per student; a background refetch never overwrites edits.
  useEffect(() => {
    if (!student.data || loadedId.current === id) return;
    loadedId.current = id;
    const next = toForm(student.data);
    setForm(next);
    setSaved(next);
  }, [student.data, id]);

  const dirty = isDirty(form, saved);

  // Unsaved edits: ask before leaving, in the page (and when closing the tab).
  const blocker = useBlocker(
    ({ currentLocation, nextLocation }) => dirty && !leaving.current && currentLocation.pathname !== nextLocation.pathname,
  );
  useEffect(() => {
    if (!dirty) return undefined;
    const warn = (e) => {
      e.preventDefault();
      e.returnValue = "";
    };
    window.addEventListener("beforeunload", warn);
    return () => window.removeEventListener("beforeunload", warn);
  }, [dirty]);

  if (role !== "staff") return <Navigate to={recordPath(id)} replace />;

  const hubPath = previousOf(id);

  const handleChange = (e) => {
    const { name, value } = e.target;

    if (name === 'enrollment_date') {
      setForm((prev) => {
        const newForm = { ...prev, enrollment_date: value };
        if (newForm.graduation_date && value > newForm.graduation_date) {
          newForm.graduation_date = '';
          staffToast.warning('Date conflict resolved', 'Graduation date cleared because it cannot be earlier than the new enrollment date.');
        }
        return newForm;
      });
    } else {
      setForm((prev) => ({ ...prev, [name]: value }));
    }

    if (errors[name]) setErrors((prev) => ({ ...prev, [name]: null }));
    if (submitStatus) setSubmitStatus(null);
  };

  const validate = () => {
    const err = {};
    if (!form.student_number?.trim())
      err.student_number = "Student number is required.";
    if (!form.first_name?.trim()) err.first_name = "First name is required.";
    if (!form.last_name?.trim()) err.last_name = "Last name is required.";
    if (!form.date_of_birth) err.date_of_birth = "Date of birth is required.";
    if (!form.sex) err.sex = "Sex is required.";
    if (!form.email?.trim()) err.email = "Email is required.";
    else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email))
      err.email = "Enter a valid email.";
    if (!form.enrollment_date) {
      err.enrollment_date = "Enrollment date is required.";
    } else {
      const today = localDateString();
      if (form.enrollment_date > today) {
        err.enrollment_date = "Enrollment date cannot be later than today.";
      }
    }
    if (form.graduation_date && form.enrollment_date && form.graduation_date < form.enrollment_date) {
      err.graduation_date = "Graduation date cannot be earlier than enrollment date.";
    }
    setErrors(err);
    return Object.keys(err).length === 0;
  };

  const handleSubmit = async (e) => {
    if (e?.preventDefault) e.preventDefault();
    setSubmitStatus(null);
    if (!validate()) return;

    setLoading(true);
    try {
      await staffApi.updateStudent(id, {
        student_number: form.student_number.trim(),
        first_name: form.first_name.trim(),
        last_name: form.last_name.trim(),
        date_of_birth: form.date_of_birth,
        sex: form.sex,
        email: form.email.trim(),
        contact_number: form.contact_number?.trim() || null,
        address: form.address?.trim() || null,
        enrollment_date: form.enrollment_date,
        graduation_date: form.graduation_date || null,
      });
      queryClient.invalidateQueries({ queryKey: [...queryKeys.staff.all, "students"] });
      queryClient.invalidateQueries({ queryKey: queryKeys.staff.studentDetail(id) });
      queryClient.invalidateQueries({ queryKey: queryKeys.staff.studentNumberMismatches() });
      setSaved(form);
      setSubmitStatus("success");
      staffToast.success("Student updated", "Student information saved.");
      leaving.current = true;
      navigate(hubPath);
    } catch (err) {
      const parsed = parseApiError(err);
      let msg = parsed.message || "Failed to update student.";
      if (parsed.errors) {
        setErrors(Object.fromEntries(Object.entries(parsed.errors).map(([k, v]) => [k, Array.isArray(v) ? v[0] : String(v)])));
        const parts = Object.values(parsed.errors).flat().filter(Boolean);
        if (parts.length) msg = parts.join(" ");
      }
      setSubmitStatus(msg);
      staffToast.error("Update failed", msg);
    } finally {
      setLoading(false);
    }
  };

  const inputBase =
    "py-2.5 px-4 rounded-lg text-base border transition-colors focus:outline-none focus:ring-2 focus:ring-tmcc/20 focus:border-tmcc";
  const inputError =
    "border-red-500 bg-red-50 focus:border-red-500 focus:ring-red-500/20";
  const inputNormal = "border-gray-300";

  if (student.isLoading || (student.data && !saved)) {
    return (
      <div className="flex flex-col items-center justify-center py-16">
        <div className="w-10 h-10 border-2 border-tmcc border-t-transparent rounded-full animate-spin" />
        <p className="mt-3 text-sm text-gray-600">Loading student...</p>
      </div>
    );
  }

  if (student.isError || !student.data) {
    return (
      <>
        <Link to={hubPath} className="inline-flex items-center gap-2 mb-6 text-tmcc text-sm font-medium no-underline hover:text-tmcc-dark hover:underline">
          <FiArrowLeft /> Edit Student
        </Link>
        <div className="p-4 rounded-lg bg-red-50 border border-red-200 text-red-800 text-sm" role="alert">
          {student.isError ? parseApiError(student.error).message || "Failed to load student." : "Student not found."}
        </div>
      </>
    );
  }

  return (
    <>
      <Link
        to={hubPath}
        className="inline-flex items-center gap-2 mb-6 text-tmcc text-sm font-medium no-underline hover:text-tmcc-dark hover:underline"
      >
        <FiArrowLeft /> Edit Student
      </Link>

      <section className="bg-white rounded-xl shadow-[0_4px_14px_rgba(0,0,0,0.08)] border border-gray-100 overflow-hidden">
        <div className="p-6 border-b border-gray-100">
          <h3 className="mt-0 mb-2 text-xl font-bold text-gray-800">
            Student Information
          </h3>
          <p className="m-0 text-gray-600 text-sm">
            {[student.data.first_name, student.data.last_name].filter(Boolean).join(" ")} · {form.student_number}.
            Update the details below; fields marked with * are required.
          </p>
        </div>

        <form className="p-6" onSubmit={handleSubmit} noValidate>
          {submitStatus && submitStatus !== "success" && (
            <div className="mb-6 p-4 rounded-lg bg-red-50 border border-red-200 text-red-800 text-sm" role="alert">{submitStatus}</div>
          )}

          <div className={cardClass}>
            <h4 className={cardTitleClass}>Personal Information</h4>
            <div className="grid grid-cols-[repeat(auto-fit,minmax(200px,1fr))] gap-4 mb-4">
              {/* The number is the login username: read-only here, changed
                  only through Change Student Number, with a reason (#56). */}
              <div className="flex flex-col gap-1.5">
                <label
                  htmlFor="student_number"
                  className="text-sm font-medium text-gray-600"
                >
                  Student Number
                </label>
                <input
                  id="student_number"
                  name="student_number"
                  type="text"
                  value={form.student_number}
                  readOnly
                  className={`${inputBase} ${inputNormal} bg-gray-100 text-gray-700 tracking-wider`}
                />
                <button
                  type="button"
                  onClick={() => setChangingNumber(true)}
                  className="self-start p-0 bg-transparent border-0 text-xs font-medium text-tmcc underline-offset-2 hover:underline"
                >
                  Change Student Number
                </button>
                {errors.student_number && (
                  <span className="text-xs text-red-600">
                    {errors.student_number}
                  </span>
                )}
              </div>
              <div className="flex flex-col gap-1.5">
                <label
                  htmlFor="date_of_birth"
                  className="text-sm font-medium text-gray-600"
                >
                  Date of Birth *
                </label>
                <input
                  id="date_of_birth"
                  name="date_of_birth"
                  type="date"
                  value={form.date_of_birth}
                  onChange={handleChange}
                  className={`${inputBase} ${errors.date_of_birth ? inputError : inputNormal}`}
                  aria-invalid={!!errors.date_of_birth}
                />
                {errors.date_of_birth && (
                  <span className="text-xs text-red-600">
                    {errors.date_of_birth}
                  </span>
                )}
              </div>
              <div className="flex flex-col gap-1.5">
                <label
                  htmlFor="sex"
                  className="text-sm font-medium text-gray-600"
                >
                  Sex *
                </label>
                <select
                  id="sex"
                  name="sex"
                  value={form.sex}
                  onChange={handleChange}
                  className={`${inputBase} ${errors.sex ? inputError : inputNormal}`}
                  aria-invalid={!!errors.sex}
                >
                  <option value="">Select sex</option>
                  <option value="M">Male</option>
                  <option value="F">Female</option>
                </select>
                {errors.sex && (
                  <span className="text-xs text-red-600">{errors.sex}</span>
                )}
              </div>
            </div>
            <div className="grid grid-cols-[repeat(auto-fit,minmax(200px,1fr))] gap-4">
              <div className="flex flex-col gap-1.5">
                <label
                  htmlFor="first_name"
                  className="text-sm font-medium text-gray-600"
                >
                  First Name *
                </label>
                <input
                  id="first_name"
                  name="first_name"
                  type="text"
                  value={form.first_name}
                  onChange={handleChange}
                  placeholder="Given name"
                  maxLength={50}
                  className={`${inputBase} ${errors.first_name ? inputError : inputNormal}`}
                  aria-invalid={!!errors.first_name}
                />
                {errors.first_name && (
                  <span className="text-xs text-red-600">
                    {errors.first_name}
                  </span>
                )}
              </div>
              <div className="flex flex-col gap-1.5">
                <label
                  htmlFor="last_name"
                  className="text-sm font-medium text-gray-600"
                >
                  Last Name *
                </label>
                <input
                  id="last_name"
                  name="last_name"
                  type="text"
                  value={form.last_name}
                  onChange={handleChange}
                  placeholder="Family name"
                  maxLength={50}
                  className={`${inputBase} ${errors.last_name ? inputError : inputNormal}`}
                  aria-invalid={!!errors.last_name}
                />
                {errors.last_name && (
                  <span className="text-xs text-red-600">
                    {errors.last_name}
                  </span>
                )}
              </div>
            </div>
          </div>

          <div className={cardClass}>
            <h4 className={cardTitleClass}>Contact Information</h4>
            <div className="grid grid-cols-[repeat(auto-fit,minmax(200px,1fr))] gap-4 mb-4">
              <div className="flex flex-col gap-1.5">
                <label
                  htmlFor="email"
                  className="text-sm font-medium text-gray-600"
                >
                  Email *
                </label>
                <input
                  id="email"
                  name="email"
                  type="email"
                  value={form.email}
                  onChange={handleChange}
                  placeholder="[EMAIL_ADDRESS]"
                  maxLength={100}
                  className={`${inputBase} ${errors.email ? inputError : inputNormal}`}
                  aria-invalid={!!errors.email}
                />
                {errors.email && (
                  <span className="text-xs text-red-600">
                    {errors.email}
                  </span>
                )}
              </div>
              <div className="flex flex-col gap-1.5">
                <label
                  htmlFor="contact_number"
                  className="text-sm font-medium text-gray-600"
                >
                  Contact Number (optional)
                </label>
                <input
                  id="contact_number"
                  name="contact_number"
                  type="tel"
                  value={form.contact_number}
                  onChange={handleChange}
                  placeholder="09XXXXXXXXX"
                  maxLength={15}
                  className={`${inputBase} ${inputNormal}`}
                />
              </div>
            </div>
            <div className="flex flex-col gap-1.5">
              <label
                htmlFor="address"
                className="text-sm font-medium text-gray-600"
              >
                Address (optional)
              </label>
              <input
                id="address"
                name="address"
                type="text"
                value={form.address}
                onChange={handleChange}
                placeholder="Street, Barangay, City"
                maxLength={150}
                className={`${inputBase} ${inputNormal}`}
              />
            </div>
          </div>

          <div className={cardClass}>
            <h4 className={cardTitleClass}>Enrollment Information</h4>
            <p className="m-0 mb-4 text-sm text-gray-600">
              The program is changed under Grades &amp; Enrollment, where its effect on enrollments is shown.
            </p>
            <div className="grid grid-cols-[repeat(auto-fit,minmax(200px,1fr))] gap-4">
              <div className="flex flex-col gap-1.5">
                <label
                  htmlFor="enrollment_date"
                  className="text-sm font-medium text-gray-600"
                >
                  Enrollment Date *
                </label>
                <input
                  id="enrollment_date"
                  name="enrollment_date"
                  type="date"
                  value={form.enrollment_date}
                  onChange={handleChange}
                  max={localDateString()}
                  className={`${inputBase} ${errors.enrollment_date ? inputError : inputNormal}`}
                  aria-invalid={!!errors.enrollment_date}
                />
                {errors.enrollment_date && (
                  <span className="text-xs text-red-600">
                    {errors.enrollment_date}
                  </span>
                )}
              </div>
              <div className="flex flex-col gap-1.5">
                <label
                  htmlFor="graduation_date"
                  className="text-sm font-medium text-gray-600"
                >
                  Graduation Date (optional)
                </label>
                <input
                  id="graduation_date"
                  name="graduation_date"
                  type="date"
                  value={form.graduation_date}
                  onChange={handleChange}
                  min={form.enrollment_date || ''}
                  className={`${inputBase} ${errors.graduation_date ? inputError : inputNormal}`}
                  aria-invalid={!!errors.graduation_date}
                />
                {errors.graduation_date && (
                  <span className="text-xs text-red-600">
                    {errors.graduation_date}
                  </span>
                )}
              </div>
            </div>
          </div>

          <div className="flex gap-4 pt-4 border-t border-gray-200">
            <button
              type="button"
              className="inline-flex items-center gap-2 py-2.5 px-5 rounded-lg text-base font-medium bg-gray-600 text-white hover:bg-gray-700 disabled:opacity-70 disabled:cursor-not-allowed"
              onClick={() => navigate(hubPath)}
              disabled={loading}
            >
              <FiArrowLeft /> Previous
            </button>
            <button
              type="submit"
              className="py-2.5 px-5 rounded-lg text-base font-medium bg-tmcc text-white hover:bg-tmcc-dark disabled:opacity-70 disabled:cursor-not-allowed"
              disabled={loading || !dirty}
            >
              {loading ? "Saving..." : "Save Changes"}
            </button>
            {!dirty && <span className="self-center text-sm text-gray-500">No changes to save.</span>}
          </div>
        </form>
      </section>

      <ConfirmDialog
        isOpen={blocker.state === "blocked"}
        onClose={() => blocker.reset?.()}
        onConfirm={() => blocker.proceed?.()}
        title="Leave without saving?"
        message="You have changes to this student's information that haven't been saved. If you leave now, they are lost."
        confirmLabel="Leave"
        cancelLabel="Stay"
        variant="danger"
      />

      {changingNumber && (
        <ChangeStudentNumberDialog
          studentId={id}
          currentNumber={form.student_number}
          enrollmentDate={formatDateForInput(student.data.enrollment_date)}
          onChanged={(res) => {
            // The number isn't an edit of this form; keep it out of "unsaved".
            setForm((prev) => ({ ...prev, student_number: res.student_number }));
            setSaved((prev) => (prev ? { ...prev, student_number: res.student_number } : prev));
            queryClient.invalidateQueries({ queryKey: [...queryKeys.staff.all, "students"] });
            queryClient.invalidateQueries({ queryKey: queryKeys.staff.studentNumberMismatches() });
            staffToast.success("Student number changed", `New login username: ${res.username ?? res.student_number}`);
          }}
          onClose={() => setChangingNumber(false)}
        />
      )}
    </>
  );
};

export default StaffEditStudentInformationPage;
