'use client';

import { useState, useEffect, useRef } from 'react';
import { motion, AnimatePresence } from 'framer-motion';
import { User, MapPin, Phone, Calendar, Mail, Briefcase, Globe, Building2, Clock, Loader2, Edit, Shield, ChevronRight, CheckCircle2, AlertCircle, Award, Languages, Star, History, Home, UserPlus, GraduationCap, Camera, X } from 'lucide-react';
import { useAuthStore } from '@/store/auth';
import { workspaceApi, type WorkspaceProfile } from '@/lib/api';
import ProfileEditModal, { type ProfileFormData } from '@/components/ProfileEditModal';

const formatDate = (dateStr: string | null) => {
  if (!dateStr) return '—';
  return new Date(dateStr).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
};

export default function ProfilePage() {
  const { user } = useAuthStore();
  const [profile, setProfile] = useState<WorkspaceProfile | null>(null);
  const [loading, setLoading] = useState(true);
  const [editModalOpen, setEditModalOpen] = useState(false);
  const [isSaving, setIsSaving] = useState(false);
  const [addExperienceOpen, setAddExperienceOpen] = useState(false);
  const [addTrainingOpen, setAddTrainingOpen] = useState(false);
  const [uploadingProfilePhoto, setUploadingProfilePhoto] = useState(false);
  const [uploadingCoverPhoto, setUploadingCoverPhoto] = useState(false);
  const profilePhotoInputRef = useRef<HTMLInputElement>(null);
  const coverPhotoInputRef = useRef<HTMLInputElement>(null);

  useEffect(() => {
    loadProfile();
  }, []);

  const loadProfile = async () => {
    try {
      setLoading(true);
      const data = await workspaceApi.myProfile();
      setProfile(data);
    } catch (error) {
      console.error('Failed to load profile:', error);
    } finally {
      setLoading(false);
    }
  };

  const handleAddMissionExperience = async (data: any) => {
    try {
      await workspaceApi.addMyMissionExperience(data);
      await loadProfile();
      setAddExperienceOpen(false);
    } catch (error: any) {
      console.error('Failed to add mission experience:', error);
      alert(`Failed to add mission experience: ${error?.message || 'Unknown error'}`);
    }
  };

  const handleRemoveMissionExperience = async (id: number) => {
    if (!confirm('Remove this mission experience from your profile?')) return;
    try {
      await workspaceApi.removeMyMissionExperience(id);
      await loadProfile();
    } catch (error: any) {
      console.error('Failed to remove mission experience:', error);
      alert(`Failed to remove: ${error?.message || 'Unknown error'}`);
    }
  };

  const handleAddTraining = async (data: any) => {
    try {
      await workspaceApi.addMyTraining(data);
      await loadProfile();
      setAddTrainingOpen(false);
    } catch (error: any) {
      console.error('Failed to add training:', error);
      alert(`Failed to add training: ${error?.message || 'Unknown error'}`);
    }
  };

  const handleRemoveTraining = async (id: number) => {
    if (!confirm('Remove this training from your profile?')) return;
    try {
      await workspaceApi.removeMyTraining(id);
      await loadProfile();
    } catch (error: any) {
      console.error('Failed to remove training:', error);
      alert(`Failed to remove: ${error?.message || 'Unknown error'}`);
    }
  };

  const handleProfilePhotoUpload = async (file: File) => {
    try {
      setUploadingProfilePhoto(true);
      await workspaceApi.uploadProfilePhoto(file);
      await loadProfile();
    } catch (error: any) {
      console.error('Failed to upload profile photo:', error);
      alert(`Failed to upload: ${error?.message || 'Unknown error'}`);
    } finally {
      setUploadingProfilePhoto(false);
    }
  };

  const handleCoverPhotoUpload = async (file: File) => {
    try {
      setUploadingCoverPhoto(true);
      await workspaceApi.uploadCoverPhoto(file);
      await loadProfile();
    } catch (error: any) {
      console.error('Failed to upload cover photo:', error);
      alert(`Failed to upload: ${error?.message || 'Unknown error'}`);
    } finally {
      setUploadingCoverPhoto(false);
    }
  };

  const handleProfilePhotoChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (file) {
      handleProfilePhotoUpload(file);
    }
  };

  const handleCoverPhotoChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (file) {
      handleCoverPhotoUpload(file);
    }
  };

  const handleSave = async (data: ProfileFormData) => {
    try {
      setIsSaving(true);

      // Filter out undefined values
      const personData: any = {};
      if (data.first_name !== undefined) personData.first_name = data.first_name;
      if (data.last_name !== undefined) personData.last_name = data.last_name;
      if (data.phone !== undefined) personData.phone = data.phone;
      if (data.gender !== undefined) personData.gender = data.gender;

      const missionaryData: any = {};
      if (data.bio !== undefined) missionaryData.bio = data.bio;
      if (data.training_notes !== undefined) missionaryData.training_notes = data.training_notes;
      if (data.home_org_unit_id !== undefined) missionaryData.home_org_unit_id = data.home_org_unit_id;

      console.log('Saving person data:', personData);
      console.log('Saving missionary data:', missionaryData);

      // Update person first, then missionary profile
      if (Object.keys(personData).length > 0) {
        await workspaceApi.updateMyPerson(personData);
      }

      if (Object.keys(missionaryData).length > 0) {
        await workspaceApi.updateMyProfile(missionaryData);
      }

      await loadProfile();
      setEditModalOpen(false);
    } catch (error: any) {
      console.error('Failed to save profile:', error);
      console.error('Error details:', JSON.stringify(error, null, 2));
      alert(`Failed to save profile: ${error?.message || 'Unknown error'}`);
      throw error;
    } finally {
      setIsSaving(false);
    }
  };

  if (loading) {
    return (
      <div className="min-h-screen p-6 flex items-center justify-center">
        <motion.div
          initial={{ opacity: 0, scale: 0.9 }}
          animate={{ opacity: 1, scale: 1 }}
          transition={{ duration: 0.3 }}
        >
          <Loader2 className="w-8 h-8 text-brand-400 animate-spin" />
        </motion.div>
      </div>
    );
  }

  return (
    <div className="min-h-screen">
      <div className="max-w-2xl mx-auto px-4 py-6 pb-32">
        {/* Header */}
        <motion.div
          initial={{ opacity: 0, y: -20 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ duration: 0.4 }}
          className="mb-6 flex items-center justify-between"
        >
          <div>
            <h1 className="text-2xl font-bold text-slate-100 mb-1">Profile</h1>
            <p className="text-sm text-slate-400">Your missionary identity</p>
          </div>
          <button
            onClick={() => setEditModalOpen(true)}
            className="h-11 px-5 rounded-2xl bg-white/5 backdrop-blur-xl border border-white/10 text-white text-sm font-medium hover:bg-white/10 transition-all flex items-center gap-2 active:scale-95"
          >
            <Edit className="w-4 h-4" />
            <span className="hidden sm:inline">Edit</span>
          </button>
        </motion.div>

        {/* Profile Identity - Liquid Glass Card */}
        <motion.div
          initial={{ opacity: 0, y: 20 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ duration: 0.4, delay: 0.1 }}
          className="mb-5"
        >
          <GlassCard className="overflow-hidden">
            {/* Cover Photo */}
            <div className="h-32 w-full relative group">
              {profile?.person?.cover_photo_url ? (
                <>
                  <img
                    src={profile.person.cover_photo_url}
                    alt="Cover"
                    className="w-full h-full object-cover"
                  />
                  <div className="absolute inset-0 bg-gradient-to-t from-slate-900/80 to-transparent" />
                  <button
                    onClick={() => coverPhotoInputRef.current?.click()}
                    disabled={uploadingCoverPhoto}
                    className="absolute top-3 right-3 bg-black/50 hover:bg-black/70 text-white p-2 rounded-full opacity-0 group-hover:opacity-100 transition-opacity"
                  >
                    {uploadingCoverPhoto ? <Loader2 className="w-4 h-4 animate-spin" /> : <Camera className="w-4 h-4" />}
                  </button>
                </>
              ) : (
                <button
                  onClick={() => coverPhotoInputRef.current?.click()}
                  disabled={uploadingCoverPhoto}
                  className="w-full h-full flex items-center justify-center bg-slate-800/50 hover:bg-slate-700/50 transition-colors text-slate-400 hover:text-slate-300"
                >
                  {uploadingCoverPhoto ? <Loader2 className="w-5 h-5 animate-spin" /> : <Camera className="w-5 h-5" />}
                </button>
              )}
              <input
                ref={coverPhotoInputRef}
                type="file"
                accept="image/*"
                onChange={handleCoverPhotoChange}
                className="hidden"
              />
            </div>
            <div className={`p-6 ${profile?.person?.cover_photo_url ? '-mt-16 relative' : ''}`}>
              <div className="flex items-start gap-4">
                {/* Avatar */}
                <div className="relative shrink-0 group">
                  <div className="w-20 h-20 rounded-2xl bg-gradient-to-br from-brand-500/20 to-brand-600/10 border border-brand-500/30 flex items-center justify-center backdrop-blur-sm overflow-hidden">
                    {(profile?.person?.photo_path || user?.person?.photo_path) ? (
                      <img
                        src={profile?.person?.photo_path || user?.person?.photo_path || ''}
                        alt={profile?.person?.full_name || user?.person?.full_name || 'Profile'}
                        className="w-full h-full rounded-2xl object-cover"
                      />
                    ) : (
                      <User className="w-10 h-10 text-brand-400" />
                    )}
                  </div>
                  <button
                    onClick={() => profilePhotoInputRef.current?.click()}
                    disabled={uploadingProfilePhoto}
                    className="absolute inset-0 bg-black/40 hover:bg-black/60 rounded-2xl flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity"
                  >
                    {uploadingProfilePhoto ? <Loader2 className="w-5 h-5 text-white animate-spin" /> : <Camera className="w-5 h-5 text-white" />}
                  </button>
                  <input
                    ref={profilePhotoInputRef}
                    type="file"
                    accept="image/*"
                    onChange={handleProfilePhotoChange}
                    className="hidden"
                  />
                  {profile?.assignment && (
                    <div className="absolute -bottom-1 -right-1 w-6 h-6 bg-brand-500 rounded-full border-2 border-slate-900 flex items-center justify-center z-10">
                      <Shield className="w-3 h-3 text-white" />
                    </div>
                  )}
                </div>

              {/* Name and bio */}
              <div className="flex-1 min-w-0">
                <div className="flex items-center gap-2 mb-1">
                  <h2 className="text-xl font-bold text-slate-100">{profile?.person?.full_name || user?.person?.full_name || '—'}</h2>
                  {profile?.missionary_profile?.deployment_status && (
                    <DeploymentStatusBadge status={profile.missionary_profile.deployment_status} />
                  )}
                </div>
                {profile?.missionary_profile?.bio && (
                  <p className="text-sm text-slate-400 line-clamp-2 mb-3">{profile.missionary_profile.bio}</p>
                )}
                <div className="flex flex-wrap gap-3">
                  <div className="flex items-center gap-1.5 text-xs text-slate-400">
                    <Mail className="w-3.5 h-3.5" />
                    <span className="truncate">{user?.email || '—'}</span>
                  </div>
                  {profile?.person?.phone && (
                    <div className="flex items-center gap-1.5 text-xs text-slate-400">
                      <Phone className="w-3.5 h-3.5" />
                      <span>{profile.person.phone}</span>
                    </div>
                  )}
                  {profile?.person?.date_of_birth && (
                    <div className="flex items-center gap-1.5 text-xs text-slate-400">
                      <Calendar className="w-3.5 h-3.5" />
                      <span>{formatDate(profile.person.date_of_birth)}</span>
                    </div>
                  )}
                  {profile?.person?.nationality && (
                    <div className="flex items-center gap-1.5 text-xs text-slate-400">
                      <Globe className="w-3.5 h-3.5" />
                      <span>{profile.person.nationality}</span>
                    </div>
                  )}
                  {profile?.person?.occupation && (
                    <div className="flex items-center gap-1.5 text-xs text-slate-400">
                      <Briefcase className="w-3.5 h-3.5" />
                      <span>{profile.person.occupation}</span>
                    </div>
                  )}
                </div>
              </div>
              </div>
            </div>
          </GlassCard>
        </motion.div>

        {/* Home Church Affiliation */}
        <motion.div
          initial={{ opacity: 0, y: 20 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ duration: 0.4, delay: 0.15 }}
          className="mb-5"
        >
          <GlassCard className="p-5">
            <div className="flex items-center gap-2 mb-4">
              <Building2 className="w-5 h-5 text-brand-400" />
              <h3 className="text-base font-semibold text-slate-100">Home Church Affiliation</h3>
            </div>
            {profile?.missionary_profile?.home_org_unit ? (
              <AffiliationHierarchy orgUnit={profile.missionary_profile.home_org_unit} />
            ) : (
              <div className="text-sm text-slate-400 py-2">No home organisation set</div>
            )}
            {profile?.org_memberships && profile.org_memberships.length > 0 && (
              <div className="mt-4 pt-4 border-t border-white/10">
                <p className="text-xs font-semibold uppercase tracking-widest text-slate-500 mb-3">Additional Memberships</p>
                <div className="space-y-2">
                  {profile.org_memberships.map((membership) => (
                    <div key={membership.id} className="flex items-center justify-between py-2">
                      <div className="flex items-center gap-2">
                        <div className="w-1.5 h-1.5 rounded-full bg-brand-400" />
                        <span className="text-sm text-slate-300">{membership.org_unit?.name || '—'}</span>
                      </div>
                      <span className="text-xs text-slate-500">{membership.membership_type || '—'}</span>
                    </div>
                  ))}
                </div>
              </div>
            )}
          </GlassCard>
        </motion.div>

        {/* Emergency Contact */}
        <motion.div
          initial={{ opacity: 0, y: 20 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ duration: 0.4, delay: 0.17 }}
          className="mb-5"
        >
          <GlassCard className="p-5">
            <div className="flex items-center gap-2 mb-4">
              <UserPlus className="w-5 h-5 text-brand-400" />
              <h3 className="text-base font-semibold text-slate-100">Emergency Contact</h3>
            </div>
            {profile?.person?.emergency_contact_name ? (
              <div className="space-y-2">
                <div className="flex items-center gap-2">
                  <div className="w-1.5 h-1.5 rounded-full bg-brand-400" />
                  <span className="text-sm text-slate-300">{profile.person.emergency_contact_name}</span>
                  {profile.person.emergency_contact_relationship && (
                    <span className="text-xs text-slate-500">({profile.person.emergency_contact_relationship})</span>
                  )}
                </div>
                {profile.person.emergency_contact_phone && (
                  <div className="flex items-center gap-2 pl-4">
                    <Phone className="w-3 h-3 text-slate-500" />
                    <span className="text-sm text-slate-400">{profile.person.emergency_contact_phone}</span>
                  </div>
                )}
              </div>
            ) : (
              <div className="text-sm text-slate-400 py-2">No emergency contact set</div>
            )}
          </GlassCard>
        </motion.div>

        {/* Residence Address */}
        <motion.div
          initial={{ opacity: 0, y: 20 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ duration: 0.4, delay: 0.19 }}
          className="mb-5"
        >
          <GlassCard className="p-5">
            <div className="flex items-center gap-2 mb-4">
              <Home className="w-5 h-5 text-brand-400" />
              <h3 className="text-base font-semibold text-slate-100">Residence Address</h3>
            </div>
            {profile?.addresses && profile.addresses.length > 0 ? (
              <div className="space-y-2">
                {profile.addresses.map((address) => (
                  <div key={address.id} className="text-sm text-slate-300">
                    <p>{address.physical_address || `${address.parish_town}, ${address.district}`}</p>
                    <p className="text-xs text-slate-500 mt-1">
                      {address.country}{address.district && `, ${address.district}`}{address.parish_town && `, ${address.parish_town}`}
                    </p>
                  </div>
                ))}
              </div>
            ) : (
              <div className="text-sm text-slate-400 py-2">No address set</div>
            )}
          </GlassCard>
        </motion.div>

        {/* Missionary Capabilities */}
        <motion.div
          initial={{ opacity: 0, y: 20 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ duration: 0.4, delay: 0.2 }}
          className="mb-5"
        >
          <GlassCard className="p-5">
            <div className="flex items-center gap-2 mb-4">
              <Briefcase className="w-5 h-5 text-brand-400" />
              <h3 className="text-base font-semibold text-slate-100">Capabilities</h3>
            </div>
            
            {/* Skills */}
            {profile?.skills && profile.skills.length > 0 && (
              <div className="mb-4">
                <p className="text-xs font-semibold uppercase tracking-widest text-slate-500 mb-3">Skills</p>
                <div className="flex flex-wrap gap-2">
                  {profile.skills.map((skill) => (
                    <SkillTag key={skill.id} name={skill.skill_name} proficiency={skill.proficiency_level} />
                  ))}
                </div>
              </div>
            )}

            {/* Languages */}
            {profile?.languages && profile.languages.length > 0 && (
              <div className="mb-4">
                <p className="text-xs font-semibold uppercase tracking-widest text-slate-500 mb-3">Languages</p>
                <div className="flex flex-wrap gap-2">
                  {profile.languages.map((lang) => (
                    <LanguageTag key={lang.id} name={lang.language_name} isNative={lang.is_native} proficiency={lang.proficiency_level} />
                  ))}
                </div>
              </div>
            )}

            {/* Training */}
            <div className="mb-4">
              <div className="flex items-center justify-between mb-3">
                <p className="text-xs font-semibold uppercase tracking-widest text-slate-500">Training & Certifications</p>
                <button
                  onClick={() => setAddTrainingOpen(true)}
                  className="text-xs text-brand-400 hover:text-brand-300 transition-colors"
                >
                  + Add
                </button>
              </div>
              {profile?.training && profile.training.length > 0 ? (
                <div className="space-y-2">
                  {profile.training.map((training) => (
                    <div key={training.id} className="bg-white/5 rounded-lg p-3 border border-white/10">
                      <div className="flex items-start justify-between gap-2">
                        <div className="flex-1">
                          <p className="text-sm font-medium text-slate-200">{training.training_name}</p>
                          <p className="text-xs text-slate-400">{training.training_type}</p>
                          {training.training_provider && (
                            <p className="text-xs text-slate-500 mt-1">{training.training_provider}</p>
                          )}
                        </div>
                        <div className="flex items-center gap-2">
                          {training.completed_on && (
                            <div className="flex items-center gap-1 text-xs text-slate-500">
                              <Calendar className="w-3 h-3" />
                              <span>{formatDate(training.completed_on)}</span>
                            </div>
                          )}
                          <button
                            onClick={() => handleRemoveTraining(training.id)}
                            className="text-xs text-slate-500 hover:text-red-400 transition-colors"
                          >
                            ×
                          </button>
                        </div>
                      </div>
                    </div>
                  ))}
                </div>
              ) : (
                <div className="text-sm text-slate-400 py-2">No training recorded</div>
              )}
            </div>

            {/* Training Notes */}
            {profile?.missionary_profile?.training_notes && (
              <div className="pt-4 border-t border-white/10">
                <p className="text-xs font-semibold uppercase tracking-widest text-slate-500 mb-2">Training Notes</p>
                <p className="text-sm text-slate-300 line-clamp-3">{profile.missionary_profile.training_notes}</p>
              </div>
            )}

            {!profile?.skills?.length && !profile?.languages?.length && !profile?.training?.length && !profile?.missionary_profile?.training_notes && (
              <div className="text-sm text-slate-400 py-2">No capabilities listed</div>
            )}
          </GlassCard>
        </motion.div>

        {/* Current Mission Assignment */}
        <motion.div
          initial={{ opacity: 0, y: 20 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ duration: 0.4, delay: 0.25 }}
          className="mb-5"
        >
          <GlassCard className="p-5">
            <div className="flex items-center gap-2 mb-4">
              <MapPin className="w-5 h-5 text-brand-400" />
              <h3 className="text-base font-semibold text-slate-100">Current Assignment</h3>
            </div>
            {profile?.assignment ? (
              <AssignmentCard assignment={profile.assignment} />
            ) : (
              <EmptyAssignment />
            )}
          </GlassCard>
        </motion.div>

        {/* Mission Journey */}
        <motion.div
          initial={{ opacity: 0, y: 20 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ duration: 0.4, delay: 0.3 }}
          className="mb-5"
        >
          <GlassCard className="p-5">
            <div className="flex items-center gap-2 mb-4">
              <History className="w-5 h-5 text-brand-400" />
              <h3 className="text-base font-semibold text-slate-100">Mission Journey</h3>
            </div>

            {/* Mission Experience */}
            <div className="mb-4">
              <div className="flex items-center justify-between mb-3">
                <p className="text-xs font-semibold uppercase tracking-widest text-slate-500">Mission Experience</p>
                <button
                  onClick={() => setAddExperienceOpen(true)}
                  className="text-xs text-brand-400 hover:text-brand-300 transition-colors"
                >
                  + Add
                </button>
              </div>
              {profile?.mission_experience && profile.mission_experience.length > 0 ? (
                <div className="space-y-3">
                  {profile.mission_experience.map((experience) => (
                    <div key={experience.id} className="bg-white/5 rounded-lg p-3 border border-white/10">
                      <div className="flex items-start justify-between gap-2 mb-2">
                        <div className="flex-1">
                          <p className="text-sm font-medium text-slate-200">{experience.mission_name}</p>
                          {experience.location && (
                            <p className="text-xs text-slate-400 flex items-center gap-1">
                              <MapPin className="w-3 h-3" />
                              {experience.location}
                            </p>
                          )}
                        </div>
                        <div className="flex items-center gap-2">
                          {experience.role && (
                            <span className="text-xs text-brand-400 bg-brand-500/10 px-2 py-0.5 rounded">{experience.role}</span>
                          )}
                          <button
                            onClick={() => handleRemoveMissionExperience(experience.id)}
                            className="text-xs text-slate-500 hover:text-red-400 transition-colors"
                          >
                            ×
                          </button>
                        </div>
                      </div>
                      {experience.description && (
                        <p className="text-xs text-slate-500 line-clamp-2 mt-2">{experience.description}</p>
                      )}
                      {(experience.started_on || experience.ended_on) && (
                        <div className="flex items-center gap-3 mt-2 text-xs text-slate-600">
                          {experience.started_on && (
                            <span>{formatDate(experience.started_on)}</span>
                          )}
                          {experience.started_on && experience.ended_on && <span>→</span>}
                          {experience.ended_on && (
                            <span>{formatDate(experience.ended_on)}</span>
                          )}
                        </div>
                      )}
                    </div>
                  ))}
                </div>
              ) : (
                <div className="text-sm text-slate-400 py-2">No mission experience recorded</div>
              )}
            </div>

            {/* Assignment History */}
            {profile?.assignment_history && profile.assignment_history.length > 0 && (
              <div className="pt-4 border-t border-white/10">
                <p className="text-xs font-semibold uppercase tracking-widest text-slate-500 mb-3">Campaign Assignments</p>
                <div className="space-y-3">
                  {profile.assignment_history.map((assignment, index) => (
                    <HistoryItem key={assignment.id} assignment={assignment} isLast={index === profile.assignment_history.length - 1} />
                  ))}
                </div>
              </div>
            )}

            {!profile?.mission_experience?.length && !profile?.assignment_history?.length && (
              <div className="text-sm text-slate-400 py-2">No mission history recorded</div>
            )}
          </GlassCard>
        </motion.div>
      </div>

      {/* Edit Profile Modal */}
      <ProfileEditModal
        isOpen={editModalOpen}
        onClose={() => setEditModalOpen(false)}
        onSave={handleSave}
        isSaving={isSaving}
        initialData={{
          first_name: user?.person?.first_name,
          last_name: user?.person?.last_name,
          phone: user?.person?.phone || undefined,
          gender: user?.person?.gender || undefined,
          bio: profile?.missionary_profile?.bio || undefined,
          training_notes: profile?.missionary_profile?.training_notes || undefined,
          home_org_unit_id: profile?.missionary_profile?.home_org_unit_id || undefined,
        }}
      />

      {/* Add Mission Experience Modal */}
      <AnimatePresence>
        {addExperienceOpen && (
          <motion.div
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            exit={{ opacity: 0 }}
            className="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4"
            onClick={() => setAddExperienceOpen(false)}
          >
            <motion.div
              initial={{ scale: 0.95, opacity: 0 }}
              animate={{ scale: 1, opacity: 1 }}
              exit={{ scale: 0.95, opacity: 0 }}
              className="bg-slate-800 rounded-2xl p-6 w-full max-w-md border border-white/10"
              onClick={(e) => e.stopPropagation()}
            >
              <h3 className="text-lg font-semibold text-slate-100 mb-4">Add Mission Experience</h3>
              <form onSubmit={(e) => {
                e.preventDefault();
                const form = e.target as HTMLFormElement;
                const data = {
                  mission_name: (form.elements.namedItem('mission_name') as HTMLInputElement).value,
                  location: (form.elements.namedItem('location') as HTMLInputElement).value || undefined,
                  started_on: (form.elements.namedItem('started_on') as HTMLInputElement).value || undefined,
                  ended_on: (form.elements.namedItem('ended_on') as HTMLInputElement).value || undefined,
                  role: (form.elements.namedItem('role') as HTMLInputElement).value || undefined,
                  description: (form.elements.namedItem('description') as HTMLTextAreaElement).value || undefined,
                };
                handleAddMissionExperience(data);
              }}>
                <div className="space-y-4">
                  <div>
                    <label className="block text-sm text-slate-400 mb-1">Mission Name *</label>
                    <input
                      name="mission_name"
                      required
                      className="w-full px-3 py-2 rounded-lg bg-white/5 border border-white/10 text-slate-100 focus:outline-none focus:border-brand-500"
                      placeholder="e.g., Central Uganda Campaign 2024"
                    />
                  </div>
                  <div>
                    <label className="block text-sm text-slate-400 mb-1">Location</label>
                    <input
                      name="location"
                      className="w-full px-3 py-2 rounded-lg bg-white/5 border border-white/10 text-slate-100 focus:outline-none focus:border-brand-500"
                      placeholder="e.g., Mbale, Uganda"
                    />
                  </div>
                  <div className="grid grid-cols-2 gap-3">
                    <div>
                      <label className="block text-sm text-slate-400 mb-1">Start Date</label>
                      <input
                        name="started_on"
                        type="date"
                        className="w-full px-3 py-2 rounded-lg bg-white/5 border border-white/10 text-slate-100 focus:outline-none focus:border-brand-500"
                      />
                    </div>
                    <div>
                      <label className="block text-sm text-slate-400 mb-1">End Date</label>
                      <input
                        name="ended_on"
                        type="date"
                        className="w-full px-3 py-2 rounded-lg bg-white/5 border border-white/10 text-slate-100 focus:outline-none focus:border-brand-500"
                      />
                    </div>
                  </div>
                  <div>
                    <label className="block text-sm text-slate-400 mb-1">Role</label>
                    <input
                      name="role"
                      className="w-full px-3 py-2 rounded-lg bg-white/5 border border-white/10 text-slate-100 focus:outline-none focus:border-brand-500"
                      placeholder="e.g., Team Leader, Bible Worker"
                    />
                  </div>
                  <div>
                    <label className="block text-sm text-slate-400 mb-1">Description</label>
                    <textarea
                      name="description"
                      rows={3}
                      className="w-full px-3 py-2 rounded-lg bg-white/5 border border-white/10 text-slate-100 focus:outline-none focus:border-brand-500 resize-none"
                      placeholder="Brief description of your mission experience..."
                    />
                  </div>
                </div>
                <div className="flex gap-3 mt-6">
                  <button
                    type="button"
                    onClick={() => setAddExperienceOpen(false)}
                    className="flex-1 px-4 py-2 rounded-lg bg-white/5 border border-white/10 text-slate-300 hover:bg-white/10 transition-colors"
                  >
                    Cancel
                  </button>
                  <button
                    type="submit"
                    className="flex-1 px-4 py-2 rounded-lg bg-brand-500 text-white hover:bg-brand-600 transition-colors"
                  >
                    Add Experience
                  </button>
                </div>
              </form>
            </motion.div>
          </motion.div>
        )}
      </AnimatePresence>

      {/* Add Training Modal */}
      <AnimatePresence>
        {addTrainingOpen && (
          <motion.div
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            exit={{ opacity: 0 }}
            className="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4"
            onClick={() => setAddTrainingOpen(false)}
          >
            <motion.div
              initial={{ scale: 0.95, opacity: 0 }}
              animate={{ scale: 1, opacity: 1 }}
              exit={{ scale: 0.95, opacity: 0 }}
              className="bg-slate-800 rounded-2xl p-6 w-full max-w-md border border-white/10"
              onClick={(e) => e.stopPropagation()}
            >
              <h3 className="text-lg font-semibold text-slate-100 mb-4">Add Training</h3>
              <form onSubmit={(e) => {
                e.preventDefault();
                const form = e.target as HTMLFormElement;
                const data = {
                  training_type: (form.elements.namedItem('training_type') as HTMLInputElement).value,
                  training_name: (form.elements.namedItem('training_name') as HTMLInputElement).value,
                  training_provider: (form.elements.namedItem('training_provider') as HTMLInputElement).value || undefined,
                  completed_on: (form.elements.namedItem('completed_on') as HTMLInputElement).value || undefined,
                  expires_on: (form.elements.namedItem('expires_on') as HTMLInputElement).value || undefined,
                  certificate_number: (form.elements.namedItem('certificate_number') as HTMLInputElement).value || undefined,
                  notes: (form.elements.namedItem('notes') as HTMLTextAreaElement).value || undefined,
                };
                handleAddTraining(data);
              }}>
                <div className="space-y-4">
                  <div>
                    <label className="block text-sm text-slate-400 mb-1">Training Type *</label>
                    <input
                      name="training_type"
                      required
                      className="w-full px-3 py-2 rounded-lg bg-white/5 border border-white/10 text-slate-100 focus:outline-none focus:border-brand-500"
                      placeholder="e.g., First Aid, Leadership"
                    />
                  </div>
                  <div>
                    <label className="block text-sm text-slate-400 mb-1">Training Name *</label>
                    <input
                      name="training_name"
                      required
                      className="w-full px-3 py-2 rounded-lg bg-white/5 border border-white/10 text-slate-100 focus:outline-none focus:border-brand-500"
                      placeholder="e.g., Advanced First Aid Certification"
                    />
                  </div>
                  <div>
                    <label className="block text-sm text-slate-400 mb-1">Provider</label>
                    <input
                      name="training_provider"
                      className="w-full px-3 py-2 rounded-lg bg-white/5 border border-white/10 text-slate-100 focus:outline-none focus:border-brand-500"
                      placeholder="e.g., Red Cross"
                    />
                  </div>
                  <div className="grid grid-cols-2 gap-3">
                    <div>
                      <label className="block text-sm text-slate-400 mb-1">Completed Date</label>
                      <input
                        name="completed_on"
                        type="date"
                        className="w-full px-3 py-2 rounded-lg bg-white/5 border border-white/10 text-slate-100 focus:outline-none focus:border-brand-500"
                      />
                    </div>
                    <div>
                      <label className="block text-sm text-slate-400 mb-1">Expires Date</label>
                      <input
                        name="expires_on"
                        type="date"
                        className="w-full px-3 py-2 rounded-lg bg-white/5 border border-white/10 text-slate-100 focus:outline-none focus:border-brand-500"
                      />
                    </div>
                  </div>
                  <div>
                    <label className="block text-sm text-slate-400 mb-1">Certificate Number</label>
                    <input
                      name="certificate_number"
                      className="w-full px-3 py-2 rounded-lg bg-white/5 border border-white/10 text-slate-100 focus:outline-none focus:border-brand-500"
                      placeholder="Certificate number if applicable"
                    />
                  </div>
                  <div>
                    <label className="block text-sm text-slate-400 mb-1">Notes</label>
                    <textarea
                      name="notes"
                      rows={2}
                      className="w-full px-3 py-2 rounded-lg bg-white/5 border border-white/10 text-slate-100 focus:outline-none focus:border-brand-500 resize-none"
                      placeholder="Additional notes..."
                    />
                  </div>
                </div>
                <div className="flex gap-3 mt-6">
                  <button
                    type="button"
                    onClick={() => setAddTrainingOpen(false)}
                    className="flex-1 px-4 py-2 rounded-lg bg-white/5 border border-white/10 text-slate-300 hover:bg-white/10 transition-colors"
                  >
                    Cancel
                  </button>
                  <button
                    type="submit"
                    className="flex-1 px-4 py-2 rounded-lg bg-brand-500 text-white hover:bg-brand-600 transition-colors"
                  >
                    Add Training
                  </button>
                </div>
              </form>
            </motion.div>
          </motion.div>
        )}
      </AnimatePresence>
    </div>
  );
}

// ── Helper Components ─────────────────────────────────────────────────────────

function GlassCard({ children, className = '' }: { children: React.ReactNode; className?: string }) {
  return (
    <div className={`glass-card ${className}`}>
      {children}
    </div>
  );
}

function AffiliationHierarchy({ orgUnit }: { orgUnit: { name: string; type: { label: string } } }) {
  return (
    <div className="space-y-2">
      <div className="flex items-center gap-2">
        <div className="w-2 h-2 rounded-full bg-brand-400" />
        <span className="text-sm font-medium text-slate-200">{orgUnit.name}</span>
      </div>
      <div className="flex items-center gap-2 pl-4">
        <div className="w-1.5 h-1.5 rounded-full bg-slate-500" />
        <span className="text-xs text-slate-400">{orgUnit.type.label}</span>
      </div>
    </div>
  );
}

function SkillTag({ name, proficiency }: { name: string; proficiency?: string }) {
  return (
    <div className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-brand-500/10 border border-brand-500/20">
      <Star className="w-3 h-3 text-brand-400" />
      <span className="text-xs font-medium text-brand-200">{name}</span>
      {proficiency && (
        <span className="text-xs text-brand-400/70">· {proficiency}</span>
      )}
    </div>
  );
}

function LanguageTag({ name, isNative, proficiency }: { name: string; isNative?: boolean; proficiency?: string }) {
  return (
    <div className="inline-flex items-center gap-1.5 px-3 py-1.5 glass-input">
      <Languages className="w-3 h-3 text-slate-400" />
      <span className="text-xs font-medium text-slate-200">{name}</span>
      {isNative && (
        <span className="text-xs text-brand-400 font-medium">Native</span>
      )}
      {!isNative && proficiency && (
        <span className="text-xs text-slate-500">· {proficiency}</span>
      )}
    </div>
  );
}

function AssignmentCard({ assignment }: { assignment: WorkspaceProfile['assignment'] }) {
  if (!assignment) return null;
  
  const statusColors = {
    confirmed: 'bg-emerald-500/20 text-emerald-300 border-emerald-500/30',
    arrived: 'bg-brand-500/20 text-brand-300 border-brand-500/30',
    completed: 'bg-slate-500/20 text-slate-300 border-slate-500/30',
    withdrew: 'bg-amber-500/20 text-amber-300 border-amber-500/30',
    no_show: 'bg-red-500/20 text-red-300 border-red-500/30',
  };
  
  const statusColor = statusColors[assignment.status as keyof typeof statusColors] || statusColors.confirmed;

  return (
    <div className="space-y-3">
      <div className="flex items-start justify-between gap-3">
        <div className="flex-1">
          <p className="text-base font-semibold text-slate-100 mb-1">{assignment.campaign.name}</p>
          {assignment.site && (
            <p className="text-sm text-slate-400 flex items-center gap-1">
              <MapPin className="w-3.5 h-3.5" />
              {assignment.site.name}
            </p>
          )}
        </div>
        <span className={`px-2.5 py-1 rounded-lg text-xs font-medium capitalize border ${statusColor}`}>
          {assignment.status}
        </span>
      </div>
      
      {assignment.functional_role && (
        <div className="flex items-center gap-2 pt-3 border-t border-white/10">
          <Award className="w-4 h-4 text-brand-400" />
          <span className="text-sm text-slate-300">{assignment.functional_role.label}</span>
        </div>
      )}
      
      <div className="flex items-center gap-4 pt-3 border-t border-white/10 text-xs text-slate-500">
        {assignment.expected_arrival && (
          <div className="flex items-center gap-1">
            <Calendar className="w-3.5 h-3.5" />
            <span>Arrival: {formatDate(assignment.expected_arrival)}</span>
          </div>
        )}
        {assignment.departure && (
          <div className="flex items-center gap-1">
            <Clock className="w-3.5 h-3.5" />
            <span>Departure: {formatDate(assignment.departure)}</span>
          </div>
        )}
      </div>
    </div>
  );
}

function EmptyAssignment() {
  return (
    <div className="text-center py-6">
      <div className="w-12 h-12 rounded-2xl bg-white/5 border border-white/10 flex items-center justify-center mx-auto mb-3">
        <MapPin className="w-6 h-6 text-slate-500" />
      </div>
      <p className="text-sm text-slate-400 mb-1">No active assignment</p>
      <p className="text-xs text-slate-500">Authorised coordinators assign missionaries to campaigns and teams</p>
    </div>
  );
}

function HistoryItem({ assignment, isLast }: { assignment: any; isLast: boolean }) {
  const statusColors = {
    confirmed: 'bg-emerald-500/20 text-emerald-300',
    arrived: 'bg-brand-500/20 text-brand-300',
    completed: 'bg-slate-500/20 text-slate-300',
    withdrew: 'bg-amber-500/20 text-amber-300',
    no_show: 'bg-red-500/20 text-red-300',
  };

  const statusColor = statusColors[assignment.status as keyof typeof statusColors] || statusColors.completed;

  return (
    <div className="relative pl-6">
      {!isLast && (
        <div className="absolute left-2 top-8 bottom-0 w-px bg-white/10" />
      )}
      <div className="absolute left-0 top-1.5 w-4 h-4 rounded-full bg-slate-700 border-2 border-slate-600" />
      <div className="bg-white/5 rounded-xl p-3 border border-white/10">
        <div className="flex items-start justify-between gap-2 mb-2">
          <p className="text-sm font-medium text-slate-200">{assignment.campaign.name}</p>
          <span className={`px-2 py-0.5 rounded-md text-xs font-medium capitalize ${statusColor}`}>
            {assignment.status}
          </span>
        </div>
        {assignment.site && (
          <p className="text-xs text-slate-400 mb-1">{assignment.site.name}</p>
        )}
        {assignment.functional_role && (
          <p className="text-xs text-slate-500">{assignment.functional_role.label}</p>
        )}
        {assignment.expected_arrival && (
          <p className="text-xs text-slate-600 mt-2">{formatDate(assignment.expected_arrival)}</p>
        )}
      </div>
    </div>
  );
}

function DeploymentStatusBadge({ status }: { status: string }) {
  const statusConfig = {
    available: { label: 'Available', color: 'bg-emerald-500/20 text-emerald-300 border-emerald-500/30' },
    deployed: { label: 'Deployed', color: 'bg-brand-500/20 text-brand-300 border-brand-500/30' },
    on_leave: { label: 'On Leave', color: 'bg-amber-500/20 text-amber-300 border-amber-500/30' },
    unavailable: { label: 'Unavailable', color: 'bg-slate-500/20 text-slate-300 border-slate-500/30' },
  };

  const config = statusConfig[status as keyof typeof statusConfig] || statusConfig.unavailable;

  return (
    <span className={`px-2 py-0.5 rounded-md text-xs font-medium border ${config.color}`}>
      {config.label}
    </span>
  );
}
