import React from 'react';
import { Link } from 'react-router-dom';
import { FiList, FiPlusCircle } from 'react-icons/fi';
import { useAuth } from '../../contexts/AuthContext';

/** Landing cards for the catalogue sections (type: 'subjects' | 'programs'). */
const LANDINGS = {
  subjects: {
    title: 'Subjects',
    intro: 'The subject catalogue shared by every program and transcript.',
    cards: [
      { path: '/staff/catalog/subjects/view', label: 'View subjects', icon: FiList, description: 'Search, filter by prefix, edit and archive subjects' },
      { path: '/staff/catalog/subjects/new', label: 'Add subject', icon: FiPlusCircle, description: 'Create a new subject in the catalogue', registrarOnly: true },
    ],
  },
  programs: {
    title: 'Programs',
    intro: 'The degree programs offered by the college.',
    note: 'New programs are created together with their curriculum (New Curriculum, coming in #70).',
    cards: [
      { path: '/staff/catalog/programs/view', label: 'View programs', icon: FiList, description: 'Search, edit and archive programs' },
    ],
  },
};

const StaffCatalogLandingPage = ({ type }) => {
  const { role } = useAuth();
  const landing = LANDINGS[type];
  const cards = landing.cards.filter((card) => !card.registrarOnly || role === 'staff');

  return (
    <>
      <section className="mb-8">
        <h2 className="m-0 text-2xl font-bold text-gray-800">{landing.title}</h2>
        <p className="mt-2 m-0 text-gray-600">{landing.intro}</p>
      </section>

      <section className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        {cards.map(({ path, label, icon: Icon, description }) => (
          <Link
            key={path}
            to={path}
            className="flex flex-col gap-3 p-6 bg-white rounded-xl shadow-[0_4px_14px_rgba(0,0,0,0.08)] border border-gray-100 no-underline text-gray-800 hover:border-tmcc/30 hover:shadow-[0_4px_18px_rgba(0,0,0,0.1)] transition-all relative"
          >
            <Icon className="w-10 h-10 text-tmcc" aria-hidden />
            <div>
              <h3 className="m-0 text-base font-semibold text-gray-800">{label}</h3>
              <p className="mt-1 m-0 text-sm text-gray-500">{description}</p>
            </div>
          </Link>
        ))}
      </section>

      {landing.note && <p className="mt-6 m-0 text-sm text-gray-600">{landing.note}</p>}
    </>
  );
};

export default StaffCatalogLandingPage;
