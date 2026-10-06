import React, { useEffect, useState } from "react";
import { useQueryClient } from "@tanstack/react-query";
import { Link, Navigate, useNavigate, useParams } from "react-router-dom";
import { FiArrowLeft } from "react-icons/fi";
import { staffApi } from "../../lib/api/staffApi";
import { staffToast } from "../../lib/notifications";
import { parseApiError } from "../../lib/api/errors";
import { queryKeys } from "../../lib/react-query/queryKeys";
import AcademicProgressionStep4 from "../../components/AcademicProgressionStep4";
import { useAuth } from "../../contexts/AuthContext";
import { useStudentQuery } from "../../hooks/useStudentQuery";
import { previousOf, studentListPath } from "../../features/students/studentRoutes";

const inputBase =
  "py-2.5 px-4 rounded-lg text-base border transition-colors focus:outline-none focus:ring-2 focus:ring-tmcc/20 focus:border-tmcc";
const inputError =
  "border-red-500 bg-red-50 focus:border-red-500 focus:ring-red-500/20";
const inputNormal = "border-gray-300";

/**
 * Edit Student → Grades & Enrollment (#55): the old step 4 exactly as it was
 * (AcademicProgressionStep4: program, academic record and bulk grades, cancel
 * enrollment, add next term with prerequisite blocking), plus the program
 * change confirmation it relies on. Registrar only.
 */
const StaffEditStudentGradesPage = () => {
  const { id } = useParams();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const { role } = useAuth();
  const student = useStudentQuery(role === "staff" ? id : null);

  const [studentProgram, setStudentProgram] = useState(null); // active program object
  const [programs, setPrograms] = useState([]);
  // Program change modal
  const [showProgramModal, setShowProgramModal] = useState(false);
  const [pendingProgramId, setPendingProgramId] = useState("");
  const [programChangeReason, setProgramChangeReason] = useState("");
  const [programChangeRemarks, setProgramChangeRemarks] = useState("");
  const [programChangeLoading, setProgramChangeLoading] = useState(false);
  const [programChangeError, setProgramChangeError] = useState("");

  useEffect(() => {
    const s = student.data;
    if (!s) return;
    if (s.program) {
      setStudentProgram(s.program);
    } else if (s.program_id) {
      setStudentProgram({ id: s.program_id, code: '', name: '' });
    } else {
      setStudentProgram(null);
    }
  }, [student.data]);

  useEffect(() => {
    staffApi
      .getPrograms()
      .then((res) => setPrograms(res?.programs || []))
      .catch(() => setPrograms([]));
  }, []);

  if (role !== "staff") return <Navigate to={studentListPath(id)} replace />;

  const hubPath = previousOf(id);
  const refreshStudent = () => {
    queryClient.invalidateQueries({ queryKey: queryKeys.staff.studentDetail(id) });
    queryClient.invalidateQueries({ queryKey: [...queryKeys.staff.all, "students"] });
  };

  const handleProgramDropdownChange = (newProgramId) => {
    if (!newProgramId) return;
    if (studentProgram && String(studentProgram.id) === String(newProgramId)) return;
    // If student already has a program, show confirmation modal
    if (studentProgram?.id) {
      setPendingProgramId(newProgramId);
      setProgramChangeReason("");
      setProgramChangeRemarks("");
      setProgramChangeError("");
      setShowProgramModal(true);
    } else {
      // No existing program — set directly
      confirmProgramSet(newProgramId);
    }
  };

  const confirmProgramSet = async (programId) => {
    // Initial set: just call updateStudentProgram with reason 'Initial assignment'
    setProgramChangeLoading(true);
    try {
      const res = await staffApi.updateStudentProgram(id, {
        new_program_id: programId,
        reason: 'Initial assignment',
      });
      const prog = programs.find((p) => String(p.id) === String(programId));
      setStudentProgram(prog || res?.student?.program || { id: programId });
      refreshStudent();
      staffToast.success('Program set', 'Student program has been assigned.');
    } catch (err) {
      staffToast.error('Failed', err?.response?.data?.message || 'Could not set program.');
    } finally {
      setProgramChangeLoading(false);
    }
  };

  const confirmProgramChange = async () => {
    if (!programChangeReason) {
      setProgramChangeError('Reason is required.');
      return;
    }
    setProgramChangeLoading(true);
    setProgramChangeError("");
    try {
      const res = await staffApi.updateStudentProgram(id, {
        new_program_id: pendingProgramId,
        reason: programChangeReason,
        remarks: programChangeRemarks || undefined,
      });
      const prog = programs.find((p) => String(p.id) === String(pendingProgramId));
      setStudentProgram(prog || res?.student?.program || { id: pendingProgramId });
      setShowProgramModal(false);
      refreshStudent();
      staffToast.success('Program changed', `Archived ${res?.archived_count ?? 0} enrollment(s). New program loaded.`);
    } catch (err) {
      setProgramChangeError(err?.response?.data?.message || 'Could not change program.');
    } finally {
      setProgramChangeLoading(false);
    }
  };

  if (student.isLoading) {
    return (
      <div className="flex flex-col items-center justify-center py-16">
        <div className="w-10 h-10 border-2 border-tmcc border-t-transparent rounded-full animate-spin" />
        <p className="mt-3 text-sm text-gray-600">Loading student...</p>
      </div>
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
            Grades &amp; Enrollment
          </h3>
          {student.data && (
            <p className="m-0 text-gray-600 text-sm">
              {[student.data.first_name, student.data.last_name].filter(Boolean).join(" ")} · {student.data.student_number}.
              Grades, enrollments and the next term are saved as you confirm each action.
            </p>
          )}
        </div>

        <div className="p-6">
          {student.isError || !student.data ? (
            <div className="p-4 rounded-lg bg-red-50 border border-red-200 text-red-800 text-sm" role="alert">
              {student.isError ? parseApiError(student.error).message || "Failed to load student." : "Student not found."}
            </div>
          ) : (
            <AcademicProgressionStep4
              studentId={id}
              studentProgram={studentProgram}
              programs={programs}
              handleProgramDropdownChange={handleProgramDropdownChange}
              programChangeLoading={programChangeLoading}
              inputBase={inputBase}
              inputNormal={inputNormal}
              inputError={inputError}
            />
          )}

          <div className="flex gap-4 mt-6 pt-4 border-t border-gray-200">
            <button
              type="button"
              className="inline-flex items-center gap-2 py-2.5 px-5 rounded-lg text-base font-medium bg-gray-600 text-white hover:bg-gray-700"
              onClick={() => navigate(hubPath)}
            >
              <FiArrowLeft /> Previous
            </button>
          </div>
        </div>
      </section>

      {/* ── Program Change Confirmation Modal ── */}
      {showProgramModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
          <div className="bg-white rounded-xl shadow-2xl p-6 max-w-md w-full mx-4">
            <h3 className="text-lg font-bold text-gray-900 mb-2">Change Student Program?</h3>
            <p className="text-sm text-gray-600 mb-4">
              Changing this student's program will <strong>archive all current active subject enrollments</strong>.
              Grades will be preserved. The new program's curriculum will become available for enrollment.
            </p>
            <div className="flex flex-col gap-3">
              <div>
                <label className="text-sm font-medium text-gray-700">Reason for program change *</label>
                <select
                  value={programChangeReason}
                  onChange={(e) => setProgramChangeReason(e.target.value)}
                  className="mt-1 w-full py-2 px-3 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-tmcc/20 focus:border-tmcc"
                >
                  <option value="">— Select reason —</option>
                  <option value="Shifted program">Shifted program</option>
                  <option value="Wrong initial encoding">Wrong initial encoding</option>
                  <option value="Transfer student">Transfer student</option>
                  <option value="Administrative correction">Administrative correction</option>
                  <option value="Other">Other</option>
                </select>
              </div>
              <div>
                <label className="text-sm font-medium text-gray-700">Remarks (optional)</label>
                <textarea
                  value={programChangeRemarks}
                  onChange={(e) => setProgramChangeRemarks(e.target.value)}
                  rows={2}
                  placeholder="Additional notes..."
                  className="mt-1 w-full py-2 px-3 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-tmcc/20 focus:border-tmcc resize-none"
                />
              </div>
              {programChangeError && <p className="text-xs text-red-600 m-0">{programChangeError}</p>}
            </div>
            <div className="flex gap-3 mt-5">
              <button
                type="button"
                onClick={confirmProgramChange}
                disabled={programChangeLoading}
                className="flex-1 py-2 px-4 rounded-lg bg-tmcc text-white text-sm font-medium disabled:opacity-70"
              >
                {programChangeLoading ? "Saving..." : "Confirm Change"}
              </button>
              <button
                type="button"
                onClick={() => { setShowProgramModal(false); setPendingProgramId(""); }}
                disabled={programChangeLoading}
                className="flex-1 py-2 px-4 rounded-lg bg-gray-200 text-gray-700 text-sm font-medium"
              >
                Cancel
              </button>
            </div>
          </div>
        </div>
      )}
    </>
  );
};

export default StaffEditStudentGradesPage;
