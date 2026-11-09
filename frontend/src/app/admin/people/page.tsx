'use client';

import { useState, useEffect } from 'react';
import { useRouter } from 'next/navigation';
import { Plus, Search, Mail, User, MoreVertical, UserPlus } from 'lucide-react';
import PersonOnboardingWizard from '@/components/PersonOnboardingWizard';
import SimplePersonRegistration from '@/components/SimplePersonRegistration';
import InvitationManagement from '@/components/InvitationManagement';
import { useAuthStore } from '@/store/auth';
import { peopleApi, outreachContactsApi } from '@/lib/api';
import AddOutreachContactModal from '@/components/admin/AddOutreachContactModal';

interface Person {
  id: number;
  first_name: string;
  last_name: string;
  email?: string;
  phone?: string | null;
  missionary_profile?: any;
}

export default function PeoplePage() {
  const router = useRouter();
  const { refreshUser } = useAuthStore();
  const [people, setPeople] = useState<Person[]>([]);
  const [outreachContacts, setOutreachContacts] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [searchQuery, setSearchQuery] = useState('');
  const [showRegistrationWizard, setShowRegistrationWizard] = useState(false);
  const [showSimpleRegistration, setShowSimpleRegistration] = useState(false);
  const [showInvitationManagement, setShowInvitationManagement] = useState(false);
  const [activeTab, setActiveTab] = useState<'people' | 'outreach'>('people');
  const [showAddOutreach, setShowAddOutreach] = useState(false);
  const [selectedPersonId, setSelectedPersonId] = useState<number | null>(null);

  useEffect(() => {
    if (activeTab === 'people') {
      fetchPeople();
    } else {
      fetchOutreachContacts();
    }
  }, [activeTab]);

  const fetchPeople = async () => {
    setLoading(true);
    try {
      const data = await peopleApi.list({ include: 'missionary_profile' });
      setPeople(data.data || []);
    } catch (error) {
      console.error('Failed to fetch people:', error);
    } finally {
      setLoading(false);
    }
  };

  const fetchOutreachContacts = async () => {
    setLoading(true);
    try {
      const data = await outreachContactsApi.list();
      setOutreachContacts(data.data || []);
    } catch (error) {
      console.error('Failed to fetch outreach contacts:', error);
    } finally {
      setLoading(false);
    }
  };

  const filteredPeople = people.filter(person =>
    `${person.first_name} ${person.last_name}`.toLowerCase().includes(searchQuery.toLowerCase()) ||
    (person.email && person.email.toLowerCase().includes(searchQuery.toLowerCase())) ||
    (person.phone && person.phone.includes(searchQuery))
  );

  const filteredOutreachContacts = outreachContacts.filter(contact =>
    `${contact.person?.first_name} ${contact.person?.last_name}`.toLowerCase().includes(searchQuery.toLowerCase()) ||
    (contact.person?.phone && contact.person.phone.includes(searchQuery))
  );

  if (showRegistrationWizard) {
    return (
      <PersonOnboardingWizard
        mode={selectedPersonId ? 'upgrade' : 'missionary'}
        personId={selectedPersonId}
        onSave={async () => {
          setShowRegistrationWizard(false);
          setSelectedPersonId(null);
          await refreshUser();
          fetchPeople();
        }}
        onCancel={() => {
          setShowRegistrationWizard(false);
          setSelectedPersonId(null);
        }}
      />
    );
  }

  if (showSimpleRegistration) {
    return (
      <SimplePersonRegistration
        onSave={async () => {
          setShowSimpleRegistration(false);
          fetchPeople();
        }}
        onCancel={() => setShowSimpleRegistration(false)}
      />
    );
  }

  if (showInvitationManagement) {
    return (
      <InvitationManagement
        onClose={() => setShowInvitationManagement(false)}
      />
    );
  }

  return (
    <>
    <div className="p-6">
      <div className="flex items-center justify-between mb-6">
        <div>
          <h1 className="text-2xl font-bold text-slate-100">People</h1>
          <p className="text-slate-400 mt-1">Manage person records and invitations</p>
        </div>
        <div className="flex items-center gap-3">
          <button
            onClick={() => setShowInvitationManagement(true)}
            className="flex items-center gap-2 px-4 py-2 bg-slate-700 hover:bg-slate-600 text-white rounded-lg text-sm font-medium transition-colors"
          >
            <Mail className="w-4 h-4" />
            Invitations
          </button>
          {activeTab === 'people' ? (
            <>
              <button
                onClick={() => setShowSimpleRegistration(true)}
                className="flex items-center gap-2 px-4 py-2 bg-slate-700 hover:bg-slate-600 text-white rounded-lg text-sm font-medium transition-colors"
              >
                <UserPlus className="w-4 h-4" />
                Register Person
              </button>
              <button
                onClick={() => setShowRegistrationWizard(true)}
                className="flex items-center gap-2 px-4 py-2 bg-brand-600 hover:bg-brand-700 text-white rounded-lg text-sm font-medium transition-colors"
              >
                <Plus className="w-4 h-4" />
                Register Missionary
              </button>
            </>
          ) : (
            <button
              onClick={() => setShowAddOutreach(true)}
              className="flex items-center gap-2 px-4 py-2 bg-brand-600 hover:bg-brand-700 text-white rounded-lg text-sm font-medium transition-colors"
            >
              <Plus className="w-4 h-4" />
              Add Outreach Contact
            </button>
          )}
        </div>
      </div>

      <div className="card">
        {/* Tab Navigation */}
        <div className="flex items-center gap-4 mb-6 border-b border-slate-700">
          <button
            onClick={() => setActiveTab('people')}
            className={`px-4 py-2 text-sm font-medium transition-colors ${
              activeTab === 'people'
                ? 'text-brand-400 border-b-2 border-brand-400'
                : 'text-slate-400 hover:text-slate-200'
            }`}
          >
            People
          </button>
          <button
            onClick={() => setActiveTab('outreach')}
            className={`px-4 py-2 text-sm font-medium transition-colors ${
              activeTab === 'outreach'
                ? 'text-brand-400 border-b-2 border-brand-400'
                : 'text-slate-400 hover:text-slate-200'
            }`}
          >
            Outreach Contacts
          </button>
        </div>

        <div className="flex items-center gap-4 mb-6">
          <div className="flex-1 relative">
            <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-500" />
            <input
              type="text"
              placeholder="Search people..."
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              className="w-full pl-10 pr-4 py-2 bg-slate-700 border border-slate-600 rounded-lg text-sm text-slate-200 placeholder-slate-500 focus:outline-none focus:border-brand-500"
            />
          </div>
        </div>

        {loading ? (
          <div className="text-center py-12 text-slate-500">Loading...</div>
        ) : activeTab === 'people' && filteredPeople.length === 0 ? (
          <div className="text-center py-12 text-slate-500">
            <User className="w-12 h-12 mx-auto mb-4 opacity-50" />
            <p>No people found</p>
          </div>
        ) : activeTab === 'outreach' && filteredOutreachContacts.length === 0 ? (
          <div className="text-center py-12 text-slate-500">
            <User className="w-12 h-12 mx-auto mb-4 opacity-50" />
            <p>No outreach contacts found</p>
          </div>
        ) : activeTab === 'people' ? (
          <div className="overflow-x-auto">
            <table className="w-full">
              <thead>
                <tr className="border-b border-slate-700">
                  <th className="text-left py-3 px-4 text-xs font-medium text-slate-400 uppercase tracking-wider">Name</th>
                  <th className="text-left py-3 px-4 text-xs font-medium text-slate-400 uppercase tracking-wider">Email</th>
                  <th className="text-left py-3 px-4 text-xs font-medium text-slate-400 uppercase tracking-wider">Phone</th>
                  <th className="text-left py-3 px-4 text-xs font-medium text-slate-400 uppercase tracking-wider">Type</th>
                  <th className="text-right py-3 px-4 text-xs font-medium text-slate-400 uppercase tracking-wider">Actions</th>
                </tr>
              </thead>
              <tbody>
                {filteredPeople.map((person) => (
                  <tr key={person.id} className="border-b border-slate-700/50 hover:bg-slate-800/50">
                    <td className="py-3 px-4">
                      <div className="flex items-center gap-3">
                        <div className="w-8 h-8 rounded-full bg-brand-600/20 flex items-center justify-center">
                          <User className="w-4 h-4 text-brand-400" />
                        </div>
                        <div>
                          <div className="text-sm font-medium text-slate-200">
                            {person.first_name} {person.last_name}
                          </div>
                        </div>
                      </div>
                    </td>
                    <td className="py-3 px-4 text-sm text-slate-400">{person.email || '-'}</td>
                    <td className="py-3 px-4 text-sm text-slate-400">{person.phone || '-'}</td>
                    <td className="py-3 px-4 text-sm">
                      {person.missionary_profile ? (
                        <span className="inline-flex items-center px-2 py-1 bg-brand-600/20 text-brand-400 rounded text-xs font-medium">
                          Missionary
                        </span>
                      ) : (
                        <span className="inline-flex items-center px-2 py-1 bg-slate-700 text-slate-400 rounded text-xs font-medium">
                          Person
                        </span>
                      )}
                    </td>
                    <td className="py-3 px-4 text-right">
                      <div className="flex items-center justify-end gap-1">
                        {!person.missionary_profile && (
                          <button
                            onClick={() => {
                              setShowRegistrationWizard(true);
                              setSelectedPersonId(person.id);
                            }}
                            className="p-2 text-slate-400 hover:text-brand-400 hover:bg-slate-700 rounded-lg transition-colors"
                            title="Register as Missionary"
                          >
                            <UserPlus className="w-4 h-4" />
                          </button>
                        )}
                        <button
                          onClick={() => router.push(`/admin/people/${person.id}`)}
                          className="p-2 text-slate-400 hover:text-brand-400 hover:bg-slate-700 rounded-lg transition-colors"
                        >
                        </button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full">
              <thead>
                <tr className="border-b border-slate-700">
                  <th className="text-left py-3 px-4 text-xs font-medium text-slate-400 uppercase tracking-wider">Name</th>
                  <th className="text-left py-3 px-4 text-xs font-medium text-slate-400 uppercase tracking-wider">Stage</th>
                  <th className="text-left py-3 px-4 text-xs font-medium text-slate-400 uppercase tracking-wider">Village</th>
                  <th className="text-left py-3 px-4 text-xs font-medium text-slate-400 uppercase tracking-wider">Registered</th>
                  <th className="text-right py-3 px-4 text-xs font-medium text-slate-400 uppercase tracking-wider">Actions</th>
                </tr>
              </thead>
              <tbody>
                {filteredOutreachContacts.map((contact) => (
                  <tr key={contact.id} className="border-b border-slate-700/50 hover:bg-slate-800/50">
                    <td className="py-3 px-4">
                      <div className="flex items-center gap-3">
                        <div className="w-8 h-8 rounded-full bg-brand-600/20 flex items-center justify-center">
                          <User className="w-4 h-4 text-brand-400" />
                        </div>
                        <div>
                          <div className="text-sm font-medium text-slate-200">
                            {contact.person?.first_name} {contact.person?.last_name}
                          </div>
                        </div>
                      </div>
                    </td>
                    <td className="py-3 px-4 text-sm text-slate-400">
                      {contact.engagement_stage?.label || '-'}
                    </td>
                    <td className="py-3 px-4 text-sm text-slate-400">{contact.village || '-'}</td>
                    <td className="py-3 px-4 text-sm text-slate-400">
                      {contact.date_registered ? new Date(contact.date_registered).toLocaleDateString() : '-'}
                    </td>
                    <td className="py-3 px-4 text-right">
                      <button
                        onClick={() => router.push(`/admin/outreach-contacts/${contact.id}`)}
                        className="p-2 text-slate-400 hover:text-brand-400 hover:bg-slate-700 rounded-lg transition-colors"
                      >
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </div>
    {showAddOutreach && (
      <AddOutreachContactModal
        open={showAddOutreach}
        onClose={() => setShowAddOutreach(false)}
        onCreated={() => fetchOutreachContacts()}
      />
    )}
    </>
  );
}
