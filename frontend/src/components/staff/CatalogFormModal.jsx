import React from 'react';
import Modal from '../ui/Modal';
import CatalogForm from './CatalogForm';

/**
 * CatalogForm in a pop-up (used for Edit). The modal renders nothing while
 * closed, so the form starts again from initialValues each time it opens.
 * See CatalogForm for onSubmit / onError / notice.
 */
const CatalogFormModal = ({ isOpen, onClose, title, idPrefix, fields, initialValues, submitLabel, onSubmit, onError, notice }) => (
  <Modal isOpen={isOpen} onClose={onClose} title={title} titleId={`${idPrefix}-title`} maxWidth="max-w-lg">
    <CatalogForm
      idPrefix={idPrefix}
      fields={fields}
      initialValues={initialValues}
      submitLabel={submitLabel}
      onSubmit={onSubmit}
      onCancel={onClose}
      onError={onError}
      notice={notice}
    />
  </Modal>
);

export default CatalogFormModal;
