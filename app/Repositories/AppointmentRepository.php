<?php

/**
 * Repository for handling appointment-related data operations.
 * File opened by Juniad on 26-07-2024.
 * Status: closed
 * --------------------------------------
 */

namespace App\Repositories;

use App\Models\Appointment;
use App\Repositories\Interfaces\AppointmentRepositoryInterface;

class AppointmentRepository implements AppointmentRepositoryInterface
{
    // Create a new appointment with the given data
    public function create(array $data)
    {
        // Generate appointment_id
        $data['appointment_id'] = $this->generateAppointmentId();
        
        return Appointment::create($data);
    }

    // Method to generate custom incrementing appointment_id
    private function generateAppointmentId()
    {
        // Get the latest appointment_id from the database
        $latestAppointment = Appointment::latest('appointment_id')->first();

        if (!$latestAppointment) {
            // If no previous appointments exist, start with 'APT0001'
            return 'APT0001';
        }

        // Extract the numeric part of the latest appointment_id (assuming it's like 'APT0001')
        $lastIdNumber = intval(substr($latestAppointment->appointment_id, 3));

        // Increment the numeric part and pad with zeroes to keep length 4
        $newIdNumber = str_pad($lastIdNumber + 1, 4, '0', STR_PAD_LEFT);

        // Return the new appointment_id, e.g., 'APT0002'
        return 'APT' . $newIdNumber;
    }

    // Find an appointment by its ID, including related patient, doctor, and payment details
    public function find($id)
    {
        return Appointment::with(['patient', 'doctor', 'payment'])->findOrFail($id);
    }

    // Retrieve all appointments, including related patient, doctor, and payment details
    public function getAll()
    {
        return Appointment::with(['patient', 'doctor', 'payment'])->get();
    }

    // Update the status of an appointment by its ID
    public function updateStatus($id, $status)
    {
        $appointment = $this->find($id);
        $appointment->status = $status;
        $appointment->save();

        return $appointment;
    }

    // Get appointment history by patient ID, including related doctor and payment details
    public function getHistoryByPatientId($patientId)
    {
        return Appointment::with(['doctor', 'payment'])
            ->where('patient_id', $patientId)
            ->get();
    }

    // Get all appointments for a specific doctor by doctor ID, including related patient and payment details
    public function getAllByDoctorId($doctorId)
    {
        return Appointment::with(['patient', 'payment'])
            ->where('doctor_id', $doctorId)
            ->get();
    }

    // Get a specific patient appointment by patient ID and appointment ID, including related doctor and payment details
    public function getPatientAppointmentById($patientId, $appointmentId)
    {
        return Appointment::with(['doctor', 'payment'])
            ->where('id', $appointmentId)
            ->where('patient_id', $patientId)
            ->firstOrFail();
    }

    // check if appointment for patient already exists
    public function findByPatientDoctorAndTime($patientId, $doctorId, $timeSlot, $date)
    {
        return Appointment::where('patient_id', $patientId)
            ->where('doctor_id', $doctorId)
            ->where('time_slot', $timeSlot)
            ->where('date', operator: $date)
            ->first();
    }

    // check if appointment time already occupied for doctor
    public function findByDocTime($doctorId, $timeSlot, $date)
    {
        return Appointment::where('doctor_id', $doctorId)
            ->where('time_slot', $timeSlot)
            ->where('date', $date)
            ->first();
    }

    // check if patient has another appointment at the same time
    public function findByPatientTime($patientId, $timeSlot, $date)
    {
        return Appointment::where('patient_id', $patientId)
            ->where('time_slot', $timeSlot)
            ->where('date', $date)
            ->first();
    }
}
