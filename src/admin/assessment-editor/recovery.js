(function (root) {
  "use strict";
  const A = root.EIPSIAssessment;
  /** Temporary questionnaire session, independently of archived partial responses. */
  class RecoverySimulation {
    constructor(clock = () => Date.now()) {
      this.clock = clock;
      this.partial = null;
      this.savedPartials = [];
      this.previousPartialSaved = false;
    }
    save(session) {
      if (session.finished) return false;
      session.metadata.data.status = "suspended";
      this.partial = {
        contract: "NATIVE_SESSION_V1",
        assessment_id: session.definition.id,
        definition: A.serialize(session.definition),
        created_at: this.clock(),
        expires_at: this.clock() + root.EIPSIAssessmentFlow.seconds(session.definition.flow.save_continue.window) * 1000,
        page: session.page,
        randomization: A.copy(session.randomization),
        answers: A.copy(session.answers),
        touched: A.copy(session.touched),
        metadata: session.metadata.snapshot(),
      };
      return true;
    }
    resume(session) {
      this.previousPartialSaved = false;
      const p = this.partial;
      if (!p || p.definition !== A.serialize(session.definition)) {
        this.partial = null;
        return false;
      }
      if (this.clock() >= p.expires_at) {
        if (session.definition.flow.save_continue.enabled && Object.keys(p.answers).length) {
          this.savedPartials.push(A.copy(p));
          this.previousPartialSaved = true;
        }
        this.partial = null;
        return false;
      }
      session.randomization = A.copy(p.randomization);
      session.resolvedDefinition = root.EIPSIAssessmentRandomization.apply(session.definition, session.randomization);
      session.pages = A.pages(session.resolvedDefinition);
      session.answers = A.copy(p.answers);
      session.touched = A.copy(p.touched);
      session.page = p.page;
      session.finished = false;
      session.metadata = new A.MetadataRecorder(session.definition, this.clock, p.metadata);
      session.metadata.present(session.definition.flow.pages[p.page].id, session.pages[p.page]);
      session.metadata.resume();
      return true;
    }
    expire() { if (this.partial) this.partial.expires_at = this.clock(); }
    clear() { this.partial = null; }
  }
  A.RecoverySimulation = RecoverySimulation;
})(typeof window !== "undefined" ? window : globalThis);
