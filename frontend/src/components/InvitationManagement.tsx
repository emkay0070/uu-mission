'use client';

import { useState, useEffect } from 'react';
import { Mail, Clock, CheckCircle, XCircle, RefreshCw, X, Search, Plus } from 'lucide-react';
import OrgHierarchyPicker from './OrgHierarchyPicker';

interface Invitation {
  id: number;
  email: string;
  person_id: number | null;
  org_unit_id: number | null;
  membership_type: string | null;
  status: 'pending' | 'accepted' | 'expired' | 'revoked';
  expires_at: string;
  accepted_at: string | null;
  created_at: string;
  person: any;
  orgUnit: any;
  createdBy: any;
}

interface InvitationManagementProps {
  onClose?: () => void;
}

export default function InvitationManagement({ onClose }: InvitationManagementProps) {
  const [invitations, setInvitations] = useState<Invitation[]>([]);
  const [loading, setLoading] = useState(true);
  const [statusFilter, setStatusFilter] = useState<string>('all');
  const [searchQuery, setSearchQuery] = useState('');
  const [showCreateForm, setShowCreateForm] = useState(false);
  const [selectedInvitation, setSelectedInvitation] = useState<Invitation | null>(null);

  useEffect(() => {
    fetchInvitations();
  }, [statusFilter]);

  const fetchInvitations = async () => {
    setLoading(true);
    try {
      const url = statusFilter === 'all'
        ? 'http://localhost:8000/api/v1/invitations'
        : `http://localhost:8000/api/v1/invitations?status=${statusFilter}`;
      
      const response = await fetch(url, {
        headers: {
          'Authorization': `Bearer ${localStorage.getItem('uu_token')}`,
        },
      });
      const data = await response.json();
      setInvitations(data.data || []);
    } catch (error) {
      console.error('Failed to fetch invitations:', error);
    } finally {
      setLoading(false);
    }
  };

  const handleRevoke = async (invitation: Invitation) => {
    if (!confirm(`Are you sure you want to revoke this invitation to ${invitation.email}?`)) {
      return;
    }

    try {
      const response = await fetch(`http://localhost:8000/api/v1/invitations/${invitation.id}/revoke`, {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${localStorage.getItem('uu_token')}`,
        },
      });

      if (response.ok) {
        fetchInvitations();
      }
    } catch (error) {
      console.error('Failed to revoke invitation:', error);
    }
  };

  const handleResend = async (invitation: Invitation) => {
    try {
      const response = await fetch(`http://localhost:8000/api/v1/invitations/${invitation.id}/resend`, {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${localStorage.getItem('uu_token')}`,
        },
      });

      if (response.ok) {
        const data = await response.json();
        alert(`Invitation resent. New token: ${data.token}`);
        fetchInvitations();
      }
    } catch (error) {
      console.error('Failed to resend invitation:', error);
    }
  };

  const getStatusIcon = (status: string) => {
    switch (status) {
      case 'pending':
        return <Clock className="w-4 h-4 text-yellow-400" />;
      case 'accepted':
        return <CheckCircle className="w-4 h-4 text-green-400" />;
      case 'expired':
        return <XCircle className="w-4 h-4 text-slate-400" />;
      case 'revoked':
        return <XCircle className="w-4 h-4 text-red-400" />;
      default:
        return null;
    }
  };

  const getStatusColor = (status: string) => {
    switch (status) {
      case 'pending':
        return 'text-yellow-400 bg-yellow-400/10';
      case 'accepted':
        return 'text-green-400 bg-green-400/10';
      case 'expired':
        return 'text-slate-400 bg-slate-400/10';
      case 'revoked':
        return 'text-red-400 bg-red-400/10';
      default:
        return 'text-slate-400 bg-slate-400/10';
    }
  };

  const filteredInvitations = invitations.filter(inv =>
    inv.email.toLowerCase().includes(searchQuery.toLowerCase()) ||
    (inv.person && `${inv.person.first_name} ${inv.person.last_name}`.toLowerCase().includes(searchQuery.toLowerCase()))
  );

  if (showCreateForm) {
    return <CreateInvitationForm onCancel={() => setShowCreateForm(false)} onSuccess={() => { setShowCreateForm(false); fetchInvitations(); }} />;
  }

  return (
    <div className="card">
      <div className="flex items-center justify-between mb-6">
        <h2 className="text-xl font-semibold text-slate-100">Invitations</h2>
        {onClose && (
          <button onClick={onClose} className="text-slate-400 hover:text-slate-300">
            <X className="w-5 h-5" />
          </button>
        )}
      </div>

      {/* Controls */}
      <div className="flex items-center gap-4 mb-6">
        <div className="flex-1 relative">
          <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-500" />
          <input
            type="text"
            placeholder="Search invitations..."
            value={searchQuery}
            onChange={(e) => setSearchQuery(e.target.value)}
            className="w-full pl-10 pr-4 py-2 bg-slate-700 border border-slate-600 rounded-lg text-sm text-slate-200 placeholder-slate-500 focus:outline-none focus:border-brand-500"
          />
        </div>
        <select
          value={statusFilter}
          onChange={(e) => setStatusFilter(e.target.value)}
          className="px-4 py-2 bg-slate-700 border border-slate-600 rounded-lg text-sm text-slate-200 focus:outline-none focus:border-brand-500"
        >
          <option value="all">All Status</option>
          <option value="pending">Pending</option>
          <option value="accepted">Accepted</option>
          <option value="expired">Expired</option>
          <option value="revoked">Revoked</option>
        </select>
        <button
          onClick={() => setShowCreateForm(true)}
          className="flex items-center gap-2 px-4 py-2 bg-brand-600 hover:bg-brand-700 text-white rounded-lg text-sm font-medium transition-colors"
        >
          <Plus className="w-4 h-4" />
          Invite
        </button>
      </div>

      {/* Invitations List */}
      {loading ? (
        <div className="text-center py-12 text-slate-500">Loading invitations...</div>
      ) : filteredInvitations.length === 0 ? (
        <div className="text-center py-12 text-slate-500">
          <Mail className="w-12 h-12 mx-auto mb-4 opacity-50" />
          <p>No invitations found</p>
        </div>
      ) : (
        <div className="space-y-3">
          {filteredInvitations.map((invitation) => (
            <div
              key={invitation.id}
              className="p-4 bg-slate-800 border border-slate-700 rounded-lg hover:border-slate-600 transition-colors"
            >
              <div className="flex items-start justify-between">
                <div className="flex-1">
                  <div className="flex items-center gap-3 mb-2">
                    <span className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium ${getStatusColor(invitation.status)}`}>
                      {getStatusIcon(invitation.status)}
                      {invitation.status.charAt(0).toUpperCase() + invitation.status.slice(1)}
                    </span>
                    <span className="text-slate-400 text-sm">{invitation.email}</span>
                  </div>
                  
                  {invitation.person && (
                    <div className="text-sm text-slate-300 mb-2">
                      {invitation.person.first_name} {invitation.person.last_name}
                    </div>
                  )}

                  {invitation.orgUnit && (
                    <div className="text-xs text-slate-500 mb-2">
                      Church: {invitation.orgUnit.name}
                    </div>
                  )}

                  <div className="flex items-center gap-4 text-xs text-slate-500">
                    <span>Created: {new Date(invitation.created_at).toLocaleDateString()}</span>
                    <span>Expires: {new Date(invitation.expires_at).toLocaleDateString()}</span>
                    {invitation.accepted_at && (
                      <span>Accepted: {new Date(invitation.accepted_at).toLocaleDateString()}</span>
                    )}
                  </div>
                </div>

                <div className="flex items-center gap-2 ml-4">
                  {invitation.status === 'pending' && (
                    <>
                      <button
                        onClick={() => handleResend(invitation)}
                        className="p-2 text-slate-400 hover:text-brand-400 hover:bg-slate-700 rounded-lg transition-colors"
                        title="Resend invitation"
                      >
                        <RefreshCw className="w-4 h-4" />
                      </button>
                      <button
                        onClick={() => handleRevoke(invitation)}
                        className="p-2 text-slate-400 hover:text-red-400 hover:bg-slate-700 rounded-lg transition-colors"
                        title="Revoke invitation"
                      >
                        <XCircle className="w-4 h-4" />
                      </button>
                    </>
                  )}
                </div>
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}

function CreateInvitationForm({ onCancel, onSuccess }: { onCancel: () => void; onSuccess: () => void }) {
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [selectedChurch, setSelectedChurch] = useState<any>(null);
  const [formData, setFormData] = useState({
    email: '',
    person_id: '',
    org_unit_id: '',
    membership_type: 'member',
  });

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setLoading(true);
    setError(null);

    try {
      const payload: any = {
        email: formData.email,
        membership_type: formData.membership_type,
      };

      if (formData.person_id) {
        payload.person_id = parseInt(formData.person_id);
      }

      if (selectedChurch) {
        payload.org_unit_id = selectedChurch.id;
      }

      const response = await fetch('http://localhost:8000/api/v1/invitations', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${localStorage.getItem('uu_token')}`,
        },
        body: JSON.stringify(payload),
      });

      const data = await response.json();

      if (!response.ok) {
        throw new Error(data.message || 'Failed to create invitation');
      }

      alert(`Invitation created successfully!\n\nToken: ${data.invitation.token}\n\nExpires: ${data.invitation.expires_at}`);
      onSuccess();
    } catch (err: any) {
      setError(err.message);
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="card">
      <div className="flex items-center justify-between mb-6">
        <h2 className="text-xl font-semibold text-slate-100">Create Invitation</h2>
        <button onClick={onCancel} className="text-slate-400 hover:text-slate-300">
          <X className="w-5 h-5" />
        </button>
      </div>

      {error && (
        <div className="mb-4 p-3 bg-red-500/10 border border-red-500/30 rounded-lg text-red-400 text-sm">
          {error}
        </div>
      )}

      <form onSubmit={handleSubmit} className="space-y-4">
        <div>
          <label className="block text-xs font-medium text-slate-400 mb-2">Email *</label>
          <input
            type="email"
            required
            value={formData.email}
            onChange={(e) => setFormData({ ...formData, email: e.target.value })}
            className="input"
            placeholder="john.doe@example.com"
          />
        </div>

        <div>
          <label className="block text-xs font-medium text-slate-400 mb-2">Link to Existing Person (Optional)</label>
          <input
            type="text"
            value={formData.person_id}
            onChange={(e) => setFormData({ ...formData, person_id: e.target.value })}
            className="input"
            placeholder="Enter person ID"
          />
          <p className="text-xs text-slate-500 mt-1">Leave empty to create a new person on acceptance</p>
        </div>

        <div>
          <label className="block text-xs font-medium text-slate-400 mb-2">Church Affiliation (Optional)</label>
          <OrgHierarchyPicker
            onSelect={(church) => setSelectedChurch(church)}
            selectedId={selectedChurch?.id}
            placeholder="Select local church..."
          />
        </div>

        <div>
          <label className="block text-xs font-medium text-slate-400 mb-2">Membership Type</label>
          <select
            value={formData.membership_type}
            onChange={(e) => setFormData({ ...formData, membership_type: e.target.value })}
            className="input"
          >
            <option value="member">Member</option>
            <option value="associate">Associate</option>
            <option value="partner">Partner</option>
          </select>
        </div>

        <div className="flex justify-end gap-3 pt-4">
          <button
            type="button"
            onClick={onCancel}
            className="px-4 py-2 rounded-lg border border-slate-600 text-slate-300 hover:bg-slate-700"
          >
            Cancel
          </button>
          <button
            type="submit"
            disabled={loading}
            className="btn-primary"
          >
            {loading ? 'Creating...' : 'Create Invitation'}
          </button>
        </div>
      </form>
    </div>
  );
}
